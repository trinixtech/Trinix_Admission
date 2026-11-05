<?php

namespace App\Modules\Merit\Services;

use App\Modules\Access\Models\User;
use App\Modules\Applicants\Models\Application;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Merit\Models\MeritComponent;
use App\Modules\Merit\Models\MeritComponentScore;
use App\Modules\Merit\Models\MeritFormula;
use App\Modules\Merit\Models\MeritList;
use App\Modules\Merit\Models\MeritListEntry;
use App\Modules\Selection\Models\SelectionStage;
use App\Support\Scope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Formulas (draft → approved with a source) and merit lists (run → approved by another person → published). */
class MeritService
{
    public function __construct(private Auditor $auditor, private MeritEngine $engine) {}

    public static function formulaRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'rounding' => ['sometimes', 'array'],
            'rounding.points' => ['integer', 'min:0', 'max:4'],
            'rounding.total' => ['integer', 'min:0', 'max:4'],
            'tie_breaks' => ['present', 'array', 'max:10'],
            'tie_breaks.*.key' => ['required', 'string', 'max:80'],
            'tie_breaks.*.direction' => ['required', 'string'],
            'components' => ['present', 'array', 'max:30'],
            'components.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'components.*.label' => ['required', 'string', 'max:120'],
            'components.*.source' => ['required', 'in:'.implode(',', MeritEngine::SOURCES)],
            'components.*.source_ref' => ['nullable', 'string', 'max:64'],
            'components.*.method' => ['required', 'in:'.implode(',', MeritEngine::METHODS)],
            'components.*.params' => ['nullable', 'array'],
            'components.*.weight' => ['required', 'numeric', 'min:0', 'max:100000', 'regex:/^\d+(\.\d{1,4})?$/'],
            'components.*.cap' => ['nullable', 'numeric', 'min:0', 'max:100000', 'regex:/^\d+(\.\d{1,4})?$/'],
            'components.*.when_missing' => ['required', 'in:zero,exclude'],
            'components.*.minimum' => ['nullable', 'array'],
        ];
    }

    public static function scope(MeritFormula|MeritList $m): Scope
    {
        $cycle = $m instanceof MeritFormula ? $m->admission_cycle_id : $m->formula->admission_cycle_id;

        return new Scope(null, $m->course_id, $cycle);
    }

    /** @return array<string, int> stage code => id */
    public static function stageIds(int $courseId, int $cycleId): array
    {
        return SelectionStage::where(['course_id' => $courseId, 'admission_cycle_id' => $cycleId])->pluck('id', 'code')->all();
    }

    // ----- Formulas ------------------------------------------------------------------------------

    public function saveDraft(int $courseId, int $cycleId, array $data, User $user, ?MeritFormula $existing = null): MeritFormula
    {
        if ($existing && $existing->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be edited. Start a new version instead.']);
        }
        if ($errors = MeritEngine::validate($data, array_keys(self::stageIds($courseId, $cycleId)))) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($courseId, $cycleId, $data, $user, $existing) {
            $values = [
                'name' => $data['name'], 'source_reference' => $data['source_reference'] ?? null,
                'rounding' => ['points' => (int) ($data['rounding']['points'] ?? 4), 'total' => (int) ($data['rounding']['total'] ?? 4)],
                'inclusion' => ['require_called' => true], // every applicant imported into the round
                'tie_breaks' => array_map(fn ($t) => ['key' => $t['key'], 'direction' => $t['direction']], $data['tie_breaks']),
            ];
            $scope = new Scope(null, $courseId, $cycleId);
            if ($existing) {
                $old = MeritEngine::definition($existing);
                $existing->forceFill($values)->save();
                $existing->components()->delete();
                $f = $existing;
            } else {
                $f = MeritFormula::create($values + [
                    'course_id' => $courseId, 'admission_cycle_id' => $cycleId, 'status' => 'draft', 'created_by' => $user->id,
                    'version' => (int) MeritFormula::where(['course_id' => $courseId, 'admission_cycle_id' => $cycleId])->max('version') + 1,
                ]);
                $old = null;
            }
            foreach (array_values($data['components']) as $i => $c) {
                MeritComponent::create([
                    'merit_formula_id' => $f->id, 'key' => $c['key'], 'label' => $c['label'], 'source' => $c['source'],
                    'source_ref' => $c['source_ref'] ?? null, 'method' => $c['method'], 'params' => $c['params'] ?? null,
                    'weight' => $c['weight'], 'cap' => ($c['cap'] ?? '') === '' ? null : $c['cap'], 'when_missing' => $c['when_missing'],
                    'minimum' => array_filter((array) ($c['minimum'] ?? []), fn ($v) => $v !== null && $v !== '') ?: null, 'sort_order' => $i,
                ]);
            }
            $f->unsetRelation('components');
            $this->auditor->record($existing ? 'merit_formula.updated' : 'merit_formula.created', $f, $old, MeritEngine::definition($f), scope: $scope);

            return $f->fresh('components');
        });
    }

    public function approveFormula(MeritFormula $f, User $user): void
    {
        if ($f->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be approved.']);
        }
        if (! trim((string) $f->source_reference)) {
            throw ValidationException::withMessages(['source_reference' => "Say where this formula comes from (IIFT's policy or a committee decision) before approving."]);
        }
        if ($f->components()->count() === 0) {
            throw ValidationException::withMessages(['components' => 'Add at least one component.']);
        }
        DB::transaction(function () use ($f, $user) {
            MeritFormula::where(['course_id' => $f->course_id, 'admission_cycle_id' => $f->admission_cycle_id, 'status' => 'approved'])->update(['status' => 'retired']);
            $this->auditor->update('merit_formula.approved', $f, ['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()], self::scope($f));
        });
    }

    /** One candidate's breakdown under a formula (draft or approved), without saving anything. */
    public function preview(MeritFormula $f, Application $app, AdmissionRound $round): array
    {
        $def = MeritEngine::definition($f);
        $in = $this->engine->inputs($def, $app, $round->id, self::stageIds($f->course_id, $f->admission_cycle_id));

        return ['inputs' => $in, 'result' => MeritEngine::score($def, $in)];
    }

    // ----- Lists ---------------------------------------------------------------------------------

    /** Run the approved formula for a course and round. Replaces any earlier unpublished run. */
    public function run(int $courseId, AdmissionRound $round, User $user): MeritList
    {
        $f = MeritFormula::where(['course_id' => $courseId, 'admission_cycle_id' => $round->admission_cycle_id, 'status' => 'approved'])->first();
        if (! $f) {
            throw ValidationException::withMessages(['formula' => 'Approve a merit formula for this course first.']);
        }
        if ($round->status === 'closed') {
            throw ValidationException::withMessages(['round_uid' => 'This round is closed.']);
        }
        $def = MeritEngine::definition($f);
        $stages = self::stageIds($courseId, $round->admission_cycle_id);

        // Candidates of this round: those who took part in the round for this course (Phase 2 participation).
        $inputs = [];
        Application::with('applicant', 'entrance', 'academics')->where('course_id', $courseId)
            ->whereHas('participations', fn ($q) => $q->where('admission_round_id', $round->id))
            ->orderBy('id')->chunkById(500, function ($apps) use (&$inputs, $def, $round, $stages) {
                foreach ($apps as $app) {
                    $inputs[$app->id] = $this->engine->inputs($def, $app, $round->id, $stages);
                }
            });

        return DB::transaction(function () use ($f, $def, $inputs, $courseId, $round, $user) {
            MeritList::where(['course_id' => $courseId, 'admission_round_id' => $round->id])->whereIn('status', ['simulated', 'approved'])->update(['status' => 'superseded']);
            $list = MeritList::create([
                'merit_formula_id' => $f->id, 'course_id' => $courseId, 'admission_round_id' => $round->id,
                'version' => (int) MeritList::where(['course_id' => $courseId, 'admission_round_id' => $round->id])->max('version') + 1,
                'status' => 'simulated', 'inputs_hash' => self::hash($def, $inputs), 'run_by' => $user->id, 'run_at' => now(),
            ]);
            $results = [];
            foreach ($inputs as $id => $in) {
                $results[$id] = MeritEngine::score($def, $in);
            }
            $rows = collect($results)->filter(fn ($r) => $r['ranked'])
                ->map(fn ($r, $id) => ['application_id' => $id, 'total' => $r['total'], 'scores' => $r['scores'],
                    'category' => $inputs[$id]['category'], 'pwbd' => $inputs[$id]['pwbd'], 'tie' => $inputs[$id]['tie']])->values()->all();
            $ranks = MeritEngine::rank($def, $rows);
            $componentIds = collect($def['components'])->pluck('id', 'key');
            foreach ($results as $id => $r) {
                $rk = $ranks[$id] ?? null;
                $entry = MeritListEntry::create([
                    'merit_list_id' => $list->id, 'application_id' => $id, 'ranked' => $r['ranked'],
                    'reason' => $r['reason'] ?? ($rk['shared'] ?? false ? 'Level after every tie-break: the committee decides' : null),
                    'total' => $r['total'], 'overall_rank' => $rk['overall'] ?? null, 'category_rank' => $rk['category'] ?? null,
                    'pwbd_rank' => $rk['pwbd'] ?? null, 'tie_shared' => $rk['shared'] ?? false, 'decided_by' => $rk['decided_by'] ?? null,
                    'inputs' => $inputs[$id],
                ]);
                MeritComponentScore::insert(collect($r['scores'])->map(fn ($s, $key) => [
                    'merit_list_entry_id' => $entry->id, 'merit_component_id' => $componentIds[$key],
                    'raw_value' => is_numeric($s['raw']) ? $s['raw'] : null, 'points' => $s['points'],
                ])->values()->all());
            }
            $this->recount($list);
            $this->auditor->record('merit_list.run', $list, null, ['formula_version' => $f->version, 'counts' => $list->counts], scope: self::scope($list));

            return $list;
        });
    }

    /** Re-score a list from its stored snapshots; must give the same totals, ranks and hash. */
    public function reproduce(MeritList $list): array
    {
        $def = MeritEngine::definition($list->formula);
        $entries = $list->entries()->get();
        $inputs = $entries->mapWithKeys(fn ($e) => [$e->application_id => $e->inputs])->all();
        $results = array_map(fn ($in) => MeritEngine::score($def, $in), $inputs);
        $rows = collect($results)->filter(fn ($r) => $r['ranked'])->map(fn ($r, $id) => ['application_id' => $id, 'total' => $r['total'], 'scores' => $r['scores'],
            'category' => $inputs[$id]['category'], 'pwbd' => $inputs[$id]['pwbd'], 'tie' => $inputs[$id]['tie']])->values()->all();
        $ranks = MeritEngine::rank($def, $rows);
        $same = self::hash($def, $inputs) === $list->inputs_hash;
        foreach ($entries as $e) {
            if ($e->manual) {
                continue; // committee decisions are recorded, not recomputed
            }
            $r = $results[$e->application_id];
            $same = $same && $r['ranked'] === $e->ranked && $r['total'] === ($e->total === null ? null : (string) BigDecimal::of($e->total)->toScale($def['rounding']['total']))
                && ($ranks[$e->application_id]['overall'] ?? null) === $e->overall_rank;
        }

        return ['same' => $same];
    }

    /** The committee orders candidates who are level after every tie-break. */
    public function resolveTie(MeritList $list, array $applicationIds, string $reason, User $user): void
    {
        if ($list->status !== 'simulated') {
            throw ValidationException::withMessages(['status' => 'Only a list that is not yet approved can be changed. Run it again for a new version.']);
        }
        $entries = MeritListEntry::where('merit_list_id', $list->id)->whereIn('application_id', $applicationIds)->get()->keyBy('application_id');
        $ranks = $entries->pluck('overall_rank')->unique();
        if ($entries->count() !== count($applicationIds) || count($applicationIds) < 2 || $ranks->count() !== 1 || $entries->contains(fn ($e) => ! $e->tie_shared)) {
            throw ValidationException::withMessages(['application_uids' => 'Pick all candidates who share one rank, in the order the committee decided.']);
        }
        $all = MeritListEntry::where('merit_list_id', $list->id)->where('overall_rank', $ranks->first())->count();
        if ($all !== count($applicationIds)) {
            throw ValidationException::withMessages(['application_uids' => "Include all $all candidates who share this rank."]);
        }
        DB::transaction(function () use ($list, $applicationIds, $entries, $ranks, $reason, $user) {
            foreach (array_values($applicationIds) as $i => $id) {
                $entries[$id]->forceFill(['overall_rank' => $ranks->first() + $i, 'tie_shared' => false, 'decided_by' => 'committee',
                    'reason' => null, 'manual' => true, 'manual_reason' => $reason, 'manual_by' => $user->id])->save();
            }
            $this->rerankSub($list);
            $this->recount($list);
            $this->auditor->record('merit_list.tie_resolved', $list, null, ['order' => Application::whereIn('id', $applicationIds)->pluck('portal_application_no', 'id')->only($applicationIds)->values()], $reason, self::scope($list));
        });
    }

    public function approve(MeritList $list, User $user): void
    {
        if ($list->status !== 'simulated') {
            throw ValidationException::withMessages(['status' => 'Only a list that has been run and not yet approved can be approved.']);
        }
        if ($list->run_by === $user->id) {
            throw ValidationException::withMessages(['approver' => 'You ran this list, so someone else must approve it.']);
        }
        DB::transaction(fn () => $this->auditor->update('merit_list.approved', $list, ['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()], self::scope($list)));
    }

    public function publish(MeritList $list, User $user): void
    {
        if ($list->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only an approved list can be published.']);
        }
        if ($list->run_by === $user->id) {
            throw ValidationException::withMessages(['publisher' => 'You ran this list, so someone else must publish it.']);
        }
        DB::transaction(function () use ($list, $user) {
            MeritList::where(['course_id' => $list->course_id, 'admission_round_id' => $list->admission_round_id, 'status' => 'published'])->update(['status' => 'superseded']);
            $this->auditor->update('merit_list.published', $list, ['status' => 'published', 'published_by' => $user->id, 'published_at' => now()], self::scope($list));
        });
    }

    /** Compare the stored inputs of a published list with today's data; flag it out of date if they differ. */
    public function check(MeritList $list): bool
    {
        $def = MeritEngine::definition($list->formula);
        $round = $list->round;
        $stages = self::stageIds($list->course_id, $round->admission_cycle_id);
        $changed = [];
        foreach ($list->entries()->with('application.applicant', 'application.entrance', 'application.academics')->get() as $e) {
            if ($this->engine->inputs($def, $e->application, $round->id, $stages) != $e->inputs) {
                $changed[] = $e->application->portal_application_no;
            }
        }
        $newcomers = Application::where('course_id', $list->course_id)->whereHas('participations', fn ($q) => $q->where('admission_round_id', $round->id))
            ->whereNotIn('id', $list->entries()->select('application_id'))->count();
        if (($changed || $newcomers) && ! $list->stale_since) {
            DB::transaction(function () use ($list, $changed, $newcomers) {
                $list->forceFill(['stale_since' => now()])->save();
                $this->auditor->record('merit_list.out_of_date', $list, null, ['changed' => array_slice($changed, 0, 50), 'new_applications' => $newcomers], scope: self::scope($list));
            });
        }

        return (bool) ($changed || $newcomers);
    }

    /** Flag published lists that include this application (marks reopened, a field corrected). */
    public static function markStale(Application $app, string $why): void
    {
        $lists = MeritList::where('status', 'published')->whereNull('stale_since')
            ->whereIn('id', MeritListEntry::where('application_id', $app->id)->select('merit_list_id'))->get();
        foreach ($lists as $list) {
            $list->forceFill(['stale_since' => now()])->save();
            app(Auditor::class)->record('merit_list.out_of_date', $list, null, ['application' => $app->portal_application_no, 'why' => $why], scope: self::scope($list));
        }
    }

    /** Differences from another version of the same course and round. */
    public function compare(MeritList $list, MeritList $other): array
    {
        $a = $list->entries()->with('application:id,uid,portal_application_no,applicant_id', 'application.applicant:id,full_name')->get()->keyBy('application_id');
        $b = $other->entries()->get()->keyBy('application_id');
        $out = [];
        foreach ($a as $id => $e) {
            $o = $b->get($id);
            if (! $o || $o->overall_rank !== $e->overall_rank || (string) $o->total !== (string) $e->total || $o->ranked !== $e->ranked) {
                $out[] = ['application_uid' => $e->application->uid, 'portal_application_no' => $e->application->portal_application_no, 'name' => $e->application->applicant->full_name,
                    'before' => $o ? ['rank' => $o->overall_rank, 'total' => $o->total, 'ranked' => $o->ranked] : null,
                    'after' => ['rank' => $e->overall_rank, 'total' => $e->total, 'ranked' => $e->ranked]];
            }
        }

        return $out;
    }

    private function rerankSub(MeritList $list): void
    {
        $entries = MeritListEntry::where('merit_list_id', $list->id)->where('ranked', true)->get()->keyBy('application_id');
        $ranks = MeritEngine::subRanks(
            $entries->map(fn ($e) => ['overall' => $e->overall_rank, 'category' => 0, 'pwbd' => null])->all(),
            $entries->map(fn ($e) => ['category' => $e->inputs['category'], 'pwbd' => $e->inputs['pwbd']])->all(),
        );
        foreach ($ranks as $id => $r) {
            $entries[$id]->forceFill(['category_rank' => $r['category'], 'pwbd_rank' => $r['pwbd']])->save();
        }
    }

    private function recount(MeritList $list): void
    {
        $rows = MeritListEntry::query()->where('merit_list_id', $list->id)->get();
        $counts = $rows->groupBy(fn ($e) => $e->inputs['category'])->map(fn ($g) => ['ranked' => $g->where('ranked', true)->count(), 'total' => $g->count()])->sortKeys()->all();
        $counts['PwBD'] = ['ranked' => $rows->filter(fn ($e) => $e->ranked && $e->inputs['pwbd'])->count(), 'total' => $rows->filter(fn ($e) => $e->inputs['pwbd'])->count()];
        $counts['_all'] = ['ranked' => $rows->where('ranked', true)->count(), 'total' => $rows->count()];
        $counts['_ties'] = $rows->where('tie_shared', true)->count();
        $list->forceFill(['counts' => $counts])->save();
    }

    public static function hash(array $def, array $inputs): string
    {
        ksort($inputs);

        return hash('sha256', json_encode(self::canonical([$def, array_values($inputs)]), JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Sort object keys recursively (lists keep their order): MySQL JSON columns do not keep key order. */
    private static function canonical(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(self::canonical(...), $v);
        if (! array_is_list($v)) {
            ksort($v);
        }

        return $v;
    }
}

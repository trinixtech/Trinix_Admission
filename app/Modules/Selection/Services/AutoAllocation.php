<?php

namespace App\Modules\Selection\Services;

use App\Modules\Access\Models\User;
use App\Modules\Access\Services\AccessControl;
use App\Modules\Applicants\Models\Application;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Models\InterviewPanelMember;
use App\Modules\Selection\Models\InterviewSlot;
use App\Modules\Selection\Models\PanelConflict;
use App\Modules\Selection\Models\SelectionStage;
use App\Support\Scope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Automatic panel allocation (Stage A, D26), always previewed before anything is saved:
 *  - candidates: called applicants not yet booked for the stage go to slots of the stage's active panels,
 *    by GD/PI centre preference, then city, then any in-person panel, then online; spread evenly;
 *  - faculty: panel members from a pool, spread evenly, without clashes (no chair unless asked).
 * Confirming goes through PanelService, so every clash check and email applies exactly as for manual work.
 */
class AutoAllocation
{
    public function __construct(private PanelService $panels, private AccessControl $access, private EvaluationService $evaluations, private Auditor $auditor) {}

    // ----- Candidates ----------------------------------------------------------------------------

    /** @param  list<int>|null  $panelIds  limit to these panels (null = all active panels of the stage and round) */
    public function previewCandidates(SelectionStage $stage, AdmissionRound $round, ?array $panelIds, bool $useCentres, User $actor): array
    {
        $panels = InterviewPanel::with('location', 'members')->where(['selection_stage_id' => $stage->id, 'admission_round_id' => $round->id, 'status' => 'active'])
            ->when($panelIds, fn ($q) => $q->whereIn('id', $panelIds))->get()->keyBy('id');
        if ($panels->isEmpty()) {
            throw ValidationException::withMessages(['stage_uid' => 'This stage has no active panels in this round.']);
        }
        $slots = InterviewSlot::whereIn('interview_panel_id', $panels->keys())->where('starts_at', '>', now())->orderBy('starts_at')->get()
            ->map(function ($s) {
                $s->setAttribute('free', $s->capacity - $s->candidates()->where('active', true)->count());

                return $s;
            })->filter(fn ($s) => $s->free > 0)->values();
        $capacity = $slots->groupBy('interview_panel_id')->map->sum('free')->all();
        $load = $panels->map(fn () => 0)->all();

        $previous = SelectionStage::where(['course_id' => $stage->course_id, 'admission_cycle_id' => $stage->admission_cycle_id])
            ->where('sort_order', '<', $stage->sort_order)->orderByDesc('sort_order')->first();
        $previousUsed = $previous && InterviewCandidate::where(['selection_stage_id' => $previous->id, 'admission_round_id' => $round->id])->exists();

        $apps = Application::with('applicant:id,full_name,city,gender,date_of_birth')->where('applications.course_id', $stage->course_id)
            ->where(fn ($q) => RoundCandidates::where($q, $round->id))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('interview_candidates as c')->whereColumn('c.application_id', 'applications.id')
                ->where('c.selection_stage_id', $stage->id)->where('c.admission_round_id', $round->id)->where('c.active', true))
            ->orderBy('portal_application_no')->get();
        $apps = self::ordered($apps, $stage->slot_order ?? []);

        $conflicts = PanelConflict::whereIn('interview_panel_id', $panels->keys())->get()
            ->filter(fn ($c) => $panels[$c->interview_panel_id]->members->whereNull('removed_at')->pluck('user_id')->contains($c->user_id))
            ->groupBy('application_id')->map(fn ($g) => $g->pluck('interview_panel_id')->unique()->all());

        $placed = [];
        $unplaced = [];
        $pending = []; // application id => what is needed to place it
        foreach ($apps as $app) {
            $after = null;
            if ($previousUsed) {
                $prev = InterviewCandidate::with('slot')->where(['application_id' => $app->id, 'selection_stage_id' => $previous->id, 'admission_round_id' => $round->id])
                    ->where('active', true)->first();
                if (! $prev || $prev->status !== 'completed') {
                    $unplaced[] = $this->row($app, null, "{$previous->code} result not locked yet");

                    continue;
                }
                if ($stage->min_previous_average !== null && EvaluationService::cents($this->evaluations->result($prev)['average']) < EvaluationService::cents($stage->min_previous_average)) {
                    $unplaced[] = $this->row($app, null, "{$previous->code} average is below the minimum {$stage->min_previous_average}");

                    continue;
                }
                $after = $prev->slot->ends_at;
            }
            $busy = InterviewCandidate::with('slot')->where('application_id', $app->id)->where('active', true)->get()
                ->map(fn ($c) => [$c->slot->starts_at, $c->slot->ends_at])->all();
            $pending[$app->id] = ['app' => $app, 'after' => $after, 'busy' => $busy, 'venue' => PanelService::earlierVenue($app, $panels->first())];
        }

        // Level by level: everyone whose first centre preference has room is placed before anyone is sent to a
        // second choice, and so on (0-2 centre preferences, 3 city, 4 another in-person panel, 5 online).
        foreach ($useCentres ? range(0, 5) : [0] as $level) {
            foreach ($pending as $appId => $p) {
                $app = $p['app'];
                $venue = $p['venue'];
                // Main rule: after an in-person GD, the PI is at the same venue, on a panel without the GD panel's members if possible.
                $shared = fn ($id) => $venue ? count(array_intersect($panels[$id]->members->whereNull('removed_at')->pluck('user_id')->all(), $venue['members'])) : 0;
                $order = $panels->keys()
                    ->filter(fn ($id) => $venue
                        ? $level === 0 && $panels[$id]->mode === 'in_person' && $panels[$id]->location_id === $venue['location_id']
                        : ! $useCentres || $this->closeness($app, $panels[$id]) === $level)
                    ->sortBy(fn ($id) => [$shared($id), ($capacity[$id] ?? 0) > 0 ? $load[$id] / max(1, $load[$id] + ($capacity[$id] ?? 0)) : 2, $id])->values();
                $chosen = null;
                foreach ($order as $pid) {
                    if (in_array($pid, $conflicts[$appId] ?? [], true)) {
                        continue;
                    }
                    foreach ($slots->where('interview_panel_id', $pid) as $slot) {
                        if ($slot->free <= 0 || ($p['after'] && $slot->starts_at < $p['after'])) {
                            continue;
                        }
                        if (! collect($p['busy'])->contains(fn ($b) => $slot->starts_at < $b[1] && $slot->ends_at > $b[0])) {
                            $chosen = $slot;
                            break 2;
                        }
                    }
                }
                if (! $chosen) {
                    continue;
                }
                $chosen->free--;
                $load[$chosen->interview_panel_id]++;
                $capacity[$chosen->interview_panel_id]--;
                $panel = $panels[$chosen->interview_panel_id];
                $why = $venue ? "Same venue as {$venue['stage']} ({$venue['location']})".($shared($panel->id) ? '' : ', other panel members') : ($useCentres ? $this->why($app, $panel) : 'Next free slot');
                $row = $this->row($app, $chosen, $why, $panel);
                $row['warning'] = PanelService::priorMarkersWarning($app, $panel);
                $placed[] = $row;
                unset($pending[$appId]);
            }
        }
        foreach ($pending as $p) {
            $unplaced[] = $this->row($p['app'], null, $p['venue'] ? "No free slot at {$p['venue']['location']} (same venue as {$p['venue']['stage']})" : 'No free slot without a clash');
        }
        usort($placed, fn ($a, $b) => [$a['when_at'], $a['panel']] <=> [$b['when_at'], $b['panel']]);

        $token = (string) Str::ulid();
        Cache::put("auto-candidates:$token", ['actor' => $actor->id, 'plan' => array_map(fn ($p) => [$p['application_id'], $p['slot_id']], $placed)], now()->addHour());

        return ['token' => $token, 'placed' => array_map(fn ($p) => array_diff_key($p, array_flip(['application_id', 'slot_id', 'when_at'])), $placed),
            'unplaced' => array_map(fn ($p) => array_diff_key($p, array_flip(['application_id', 'slot_id', 'when_at'])), $unplaced)];
    }

    public function confirmCandidates(string $token, User $actor): array
    {
        $plan = $this->take("auto-candidates:$token", $actor);
        $done = 0;
        $failed = [];
        foreach ($plan as [$appId, $slotId]) {
            $app = Application::find($appId);
            try {
                $this->panels->schedule($app, InterviewSlot::findOrFail($slotId), $actor);
                $done++;
            } catch (ValidationException $e) {
                $failed[] = ['portal_application_no' => $app->portal_application_no, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['scheduled' => $done, 'failed' => $failed];
    }

    public const ORDER_KEYS = ['name', 'gender', 'date_of_birth', 'portal_application_no'];

    /**
     * Slot order rules (D28): the admin's keys, in order, then the portal application number so the result is stable.
     *
     * @param  list<array{key: string, direction: string}>  $rules
     */
    public static function ordered(Collection $apps, array $rules): Collection
    {
        $value = fn (Application $a, string $key) => match ($key) {
            'name' => mb_strtolower((string) $a->applicant->full_name),
            'gender' => mb_strtolower((string) $a->applicant->gender),
            'date_of_birth' => (string) $a->applicant->date_of_birth?->toDateString(),
            default => (string) $a->portal_application_no,
        };
        $rules[] = ['key' => 'portal_application_no', 'direction' => 'asc'];

        return $apps->sort(function ($x, $y) use ($rules, $value) {
            foreach ($rules as $r) {
                $vx = $value($x, $r['key']);
                $vy = $value($y, $r['key']);
                if ($vx === '' && $vy !== '') {
                    return 1; // missing values last
                }
                if ($vy === '' && $vx !== '') {
                    return -1;
                }
                if ($c = strcmp($vx, $vy)) {
                    return $r['direction'] === 'desc' ? -$c : $c;
                }
            }

            return 0;
        })->values();
    }

    /** 0-2: the applicant's GD/PI centre preferences in order; 3: their city; 4: another in-person panel; 5: online. */
    private function closeness(Application $app, InterviewPanel $panel): int
    {
        if ($panel->mode === 'online') {
            return 5;
        }
        $city = $panel->location?->city;
        foreach (array_values($app->interview_centres ?? []) as $i => $c) {
            if (self::sameCity($c, $city)) {
                return $i;
            }
        }

        return self::sameCity($app->applicant->city, $city) ? 3 : 4;
    }

    private function why(Application $app, InterviewPanel $panel): string
    {
        return match ($n = $this->closeness($app, $panel)) {
            0, 1, 2 => 'GD/PI centre preference '.($n + 1),
            3 => "Applicant's city",
            4 => 'Another in-person centre (preferred centres full or not available)',
            default => 'Online (no in-person place available)',
        };
    }

    public static function sameCity(?string $a, ?string $b): bool
    {
        $norm = fn ($s) => trim(preg_replace('/\b(new|city)\b/', '', mb_strtolower((string) $s)));
        [$a, $b] = [$norm($a), $norm($b)];

        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }

    private function row(Application $app, ?InterviewSlot $slot, string $reason, ?InterviewPanel $panel = null): array
    {
        $tz = config('app.display_timezone');

        return [
            'application_id' => $app->id, 'slot_id' => $slot?->id,
            'application_uid' => $app->uid, 'portal_application_no' => $app->portal_application_no, 'name' => $app->applicant->full_name,
            'city' => $app->applicant->city, 'centres' => $app->interview_centres ?? [],
            'panel' => $panel?->name, 'panel_city' => $panel?->location?->city, 'when' => $slot?->starts_at->setTimezone($tz)->format('D j M, g:i A'),
            'reason' => $reason, 'when_at' => $slot?->starts_at->getTimestamp(),
        ];
    }

    // ----- Faculty -------------------------------------------------------------------------------

    /**
     * @param  list<int>  $panelIds
     */
    public function previewFaculty(SelectionStage $stage, AdmissionRound $round, ?array $panelIds, int $perPanel, bool $nameChair, ?Branch $campus, User $actor): array
    {
        $scope = new Scope(null, $stage->course_id, $stage->admission_cycle_id);
        $panels = InterviewPanel::with('slots', 'members')->where(['selection_stage_id' => $stage->id, 'admission_round_id' => $round->id, 'status' => 'active'])
            ->when($panelIds, fn ($q) => $q->whereIn('id', $panelIds))->orderBy('panel_date')->orderBy('name')->get();
        $pool = User::where('status', User::ACTIVE)->orderBy('name')->get()
            ->filter(fn ($u) => $this->access->can($u, 'evaluations.submit', $scope))
            ->filter(fn ($u) => ! $campus || $u->activeAssignments()->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $campus->id))->exists())
            ->values();
        if ($pool->isEmpty()) {
            throw ValidationException::withMessages(['pool' => 'Nobody in scope can mark candidates. Import or invite faculty first.']);
        }
        $load = $pool->mapWithKeys(fn ($u) => [$u->id => InterviewPanelMember::whereNull('removed_at')->where('user_id', $u->id)
            ->whereHas('panel', fn ($q) => $q->where('admission_round_id', $round->id))->count()])->all();
        $times = []; // user id => [[starts, ends]] planned in this preview
        $plan = [];
        $short = [];
        foreach ($panels as $panel) {
            $current = $panel->members->whereNull('removed_at');
            $need = $perPanel - $current->count();
            if ($need <= 0) {
                continue;
            }
            $hasChair = $current->contains('role', 'chair');
            $picked = [];
            foreach ($pool->sortBy(fn ($u) => [$load[$u->id], $u->name])->values() as $u) {
                if (count($picked) >= $need) {
                    break;
                }
                if ($current->contains('user_id', $u->id)) {
                    continue;
                }
                $clash = $panel->slots->contains(fn ($s) => $this->panels->memberClash($u, $s->starts_at, $s->ends_at, $panel->id)
                    || collect($times[$u->id] ?? [])->contains(fn ($t) => $s->starts_at < $t[1] && $s->ends_at > $t[0]));
                if ($clash) {
                    continue;
                }
                $picked[] = $u;
                $load[$u->id]++;
                foreach ($panel->slots as $s) {
                    $times[$u->id][] = [$s->starts_at, $s->ends_at];
                }
            }
            $chairId = null;
            if ($nameChair && ! $hasChair && $picked) {
                $chairId = (collect($picked)->first(fn ($u) => $u->activeAssignments()->whereHas('role', fn ($r) => $r->where('key', 'committee_chair'))->exists()) ?? $picked[0])->id;
            }
            foreach ($picked as $u) {
                $plan[] = ['panel_id' => $panel->id, 'user_id' => $u->id, 'role' => $u->id === $chairId ? 'chair' : 'member',
                    'panel' => $panel->name, 'date' => $panel->panel_date->toDateString(), 'name' => $u->name, 'designation' => $u->designation];
            }
            if (count($picked) < $need) {
                $short[] = ['panel' => $panel->name, 'missing' => $need - count($picked)];
            }
        }
        $token = (string) Str::ulid();
        Cache::put("auto-faculty:$token", ['actor' => $actor->id, 'plan' => array_map(fn ($p) => [$p['panel_id'], $p['user_id'], $p['role']], $plan)], now()->addHour());

        return ['token' => $token, 'assignments' => array_map(fn ($p) => array_diff_key($p, array_flip(['panel_id', 'user_id'])), $plan), 'short' => $short];
    }

    public function confirmFaculty(string $token, User $actor): array
    {
        $done = 0;
        $failed = [];
        foreach ($this->take("auto-faculty:$token", $actor) as [$panelId, $userId, $role]) {
            $user = User::find($userId);
            try {
                $this->panels->addMember(InterviewPanel::findOrFail($panelId), $user, $role, $actor);
                $done++;
            } catch (ValidationException $e) {
                $failed[] = ['name' => $user->name, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['added' => $done, 'failed' => $failed];
    }

    private function take(string $key, User $actor): array
    {
        $staged = Cache::pull($key);
        if (! $staged || $staged['actor'] !== $actor->id) {
            throw ValidationException::withMessages(['token' => 'This preview has expired or belongs to someone else. Make a new one.']);
        }

        return $staged['plan'];
    }

    /** @return Collection<int, InterviewPanel> */
    public static function panelsOf(SelectionStage $stage, AdmissionRound $round): Collection
    {
        return InterviewPanel::where(['selection_stage_id' => $stage->id, 'admission_round_id' => $round->id])->get();
    }
}

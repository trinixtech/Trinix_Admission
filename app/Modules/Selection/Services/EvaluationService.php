<?php

namespace App\Modules\Selection\Services;

use App\Modules\Access\Models\User;
use App\Modules\Access\Services\AccessControl;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Merit\Services\MeritService;
use App\Modules\Selection\Models\EvaluationScoreHistory;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\InterviewEvaluation;
use App\Modules\Selection\Models\InterviewEvaluationScore;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Models\InterviewSlot;
use App\Modules\Selection\Models\PanelConflict;
use App\Modules\Selection\Models\SelectionStage;
use Brick\Math\Exception\DivisionByZeroException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Live marking (D7): autosave drafts, submit (locks), reopen with a reason, chair locks the result.
 * Every current panel member who has not declared a conflict marks every candidate; the result is the
 * average of their totals (D24). Marks are DECIMAL(6,2); arithmetic is done in hundredths to stay exact.
 */
class EvaluationService
{
    public function __construct(private Auditor $auditor, private AccessControl $access, private PanelService $panels) {}

    public static function cents(mixed $marks): int
    {
        return (int) round(((float) $marks) * 100);
    }

    public static function fromCents(int $c): string
    {
        return number_format($c / 100, 2, '.', '');
    }

    /** @return list<int> user ids expected to mark this candidate */
    public function evaluators(InterviewCandidate $c): array
    {
        $panelId = $c->slot->interview_panel_id;
        $conflicted = PanelConflict::where(['interview_panel_id' => $panelId, 'application_id' => $c->application_id])->pluck('user_id')->all();

        return InterviewPanel::find($panelId)->members()->whereNull('removed_at')->whereNotIn('user_id', $conflicted)->pluck('user_id')->all();
    }

    public function canEvaluate(User $user, InterviewCandidate $c): bool
    {
        return $this->access->hasAnywhere($user, 'evaluations.submit') && in_array($user->id, $this->evaluators($c), true);
    }

    public function evaluation(InterviewCandidate $c, User $user): InterviewEvaluation
    {
        return InterviewEvaluation::firstOrCreate(['interview_candidate_id' => $c->id, 'evaluator_id' => $user->id], ['status' => 'draft']);
    }

    /**
     * Autosave: any subset of criteria (null clears a box) and remarks.
     *
     * @param  array<int|string, mixed>  $marks  criterion id => marks or null
     */
    public function save(InterviewCandidate $c, User $user, array $marks, ?string $remarks): InterviewEvaluation
    {
        $this->ensureCanMark($c, $user);
        $ev = $this->evaluation($c, $user);
        if ($ev->status === 'submitted') {
            throw ValidationException::withMessages(['status' => 'You have submitted these marks. Ask the chair to reopen them.']);
        }
        $criteria = $c->stage->criteria()->where('status', 'active')->get()->keyBy('id');
        $errors = [];
        foreach ($marks as $id => $value) {
            $crit = $criteria[(int) $id] ?? null;
            if (! $crit) {
                $errors["marks.$id"] = 'Unknown criterion.';

                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_numeric($value) || ! preg_match('/^\d+(\.\d{1,2})?$/', (string) $value)) {
                $errors["marks.$id"] = 'Use a number with at most two decimals.';
            } elseif (self::cents($value) > self::cents($crit->max_marks)) {
                $errors["marks.$id"] = "{$crit->name}: at most {$crit->max_marks}.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        DB::transaction(function () use ($ev, $marks, $remarks) {
            foreach ($marks as $id => $value) {
                InterviewEvaluationScore::updateOrCreate(
                    ['interview_evaluation_id' => $ev->id, 'evaluation_criterion_id' => (int) $id],
                    ['marks' => ($value === null || $value === '') ? null : $value],
                );
            }
            if ($remarks !== null) {
                $ev->forceFill(['remarks' => $remarks])->save();
            }
            $ev->touch();
        });

        return $ev->fresh('scores');
    }

    public function submit(InterviewCandidate $c, User $user): InterviewEvaluation
    {
        $this->ensureCanMark($c, $user);
        $ev = $this->evaluation($c, $user);
        if ($ev->status === 'submitted') {
            throw ValidationException::withMessages(['status' => 'Already submitted.']);
        }
        $criteria = $c->stage->criteria()->where('status', 'active')->pluck('id');
        $scores = $ev->scores()->whereNotNull('marks')->pluck('marks', 'evaluation_criterion_id');
        $missing = $criteria->diff($scores->keys());
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['marks' => 'Fill every criterion before submitting.']);
        }
        $total = $scores->sum(fn ($m) => self::cents($m));
        $rule = $c->stage->remark_rule ?? ['required' => 'always'];
        $needRemark = ($rule['required'] ?? 'always') === 'always'
            || (($rule['required'] ?? null) === 'below' && $total < self::cents($rule['below'] ?? 0));
        if ($needRemark && ! trim((string) $ev->remarks)) {
            throw ValidationException::withMessages(['remarks' => 'Remarks are required.']);
        }
        DB::transaction(function () use ($ev, $user, $c, $total) {
            $ev->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
            $this->history($ev, 'submitted', null, $user);
            $this->auditor->record('evaluation.submitted', $c->application, null, ['stage' => $c->stage->code, 'total' => self::fromCents($total)],
                scope: $c->application->scope());
        });

        return $ev->fresh('scores');
    }

    public function reopen(InterviewEvaluation $ev, string $reason, User $actor): void
    {
        $c = $ev->candidate;
        if (! $this->mayManage($actor, $c)) {
            throw new AuthorizationException('Only the panel chair or a Central Admin can reopen marks.');
        }
        if ($ev->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'Only submitted marks can be reopened.']);
        }
        DB::transaction(function () use ($ev, $reason, $actor, $c) {
            $this->history($ev, 'reopened', $reason, $actor);
            $ev->forceFill(['status' => 'reopened', 'reopened_by' => $actor->id, 'reopened_at' => now(), 'reopen_reason' => $reason])->save();
            if ($c->result_locked_at) {
                $c->forceFill(['result_locked_at' => null, 'result_locked_by' => null, 'status' => 'present'])->save();
            }
            MeritService::markStale($c->application, "{$c->stage->code} marks reopened");
            $this->auditor->record('evaluation.reopened', $c->application, null, ['evaluator' => $ev->evaluator->uid, 'stage' => $c->stage->code], $reason, $c->application->scope());
        });
    }

    public function lock(InterviewCandidate $c, User $actor): void
    {
        if (! $this->mayManage($actor, $c)) {
            throw new AuthorizationException('Only the panel chair or a Central Admin can lock results.');
        }
        if ($c->status !== 'present') {
            throw ValidationException::withMessages(['candidate' => $c->status === 'completed' ? 'Already locked.' : 'Only a present candidate has results to lock.']);
        }
        $expected = $this->evaluators($c);
        $submitted = InterviewEvaluation::where('interview_candidate_id', $c->id)->where('status', 'submitted')->pluck('evaluator_id')->all();
        if ($missing = array_diff($expected, $submitted)) {
            throw ValidationException::withMessages(['candidate' => count($missing).' panel member(s) have not submitted yet.']);
        }
        DB::transaction(function () use ($c, $actor) {
            $c->forceFill(['status' => 'completed', 'result_locked_at' => now(), 'result_locked_by' => $actor->id])->save();
            $this->auditor->record('evaluation.locked', $c->application, null, ['stage' => $c->stage->code, 'average' => $this->result($c)['average']], scope: $c->application->scope());
        });
    }

    /** Totals per evaluator, the average and outlier flags. */
    public function result(InterviewCandidate $c): array
    {
        $c->loadMissing('stage');
        $evs = InterviewEvaluation::with('scores', 'evaluator:id,uid,name')->where('interview_candidate_id', $c->id)->where('status', 'submitted')->get();
        $totals = $evs->mapWithKeys(fn ($e) => [$e->evaluator_id => $e->scores->sum(fn ($s) => self::cents($s->marks))]);
        $avg = $totals->isEmpty() ? null : intdiv($totals->sum() * 2 + $totals->count(), $totals->count() * 2); // rounded half up
        $threshold = $c->stage->outlier_threshold !== null ? self::cents($c->stage->outlier_threshold) : null;

        // The stage result: the admin's formula over members' totals (D26) when enough members marked; else the plain average.
        [$result, $rule] = [$avg === null ? null : self::fromCents($avg), 'AVG'];
        $formula = $c->stage->result_formula ?: 'AVG';
        if ($avg !== null && $formula !== 'AVG' && $totals->count() >= max(1, (int) $c->stage->formula_min_members)) {
            try {
                $result = StageFormula::evaluate($formula, $totals->map(fn ($t) => self::fromCents($t))->values()->all());
                $rule = $formula;
            } catch (DivisionByZeroException) {
                $rule = "AVG (the formula divides by zero for {$totals->count()} members)";
            }
        } elseif ($avg !== null && $formula !== 'AVG') {
            $rule = "AVG (fewer than {$c->stage->formula_min_members} members marked)";
        }

        return [
            'average' => $result,
            'plain_average' => $avg === null ? null : self::fromCents($avg),
            'rule' => $rule,
            'locked' => $c->result_locked_at !== null,
            'evaluators' => $evs->map(fn ($e) => [
                'uid' => $e->uid, 'evaluator' => $e->evaluator->only(['uid', 'name']), 'total' => self::fromCents($totals[$e->evaluator_id]),
                'outlier' => $threshold !== null && $avg !== null && abs($totals[$e->evaluator_id] - $avg) > $threshold,
                'scores' => $e->scores->mapWithKeys(fn ($s) => [$s->evaluation_criterion_id => $s->marks]), 'remarks' => $e->remarks,
            ])->values()->all(),
        ];
    }

    /**
     * Send candidates whose results are locked on to the next stage's panel: each gets that panel's
     * next free slot after their current one. The next stage may require a minimum average here.
     *
     * @param  list<InterviewCandidate>  $candidates
     * @return list<array{uid: string, sent: bool, reason: ?string, slot: ?string}>
     */
    public function sendOn(array $candidates, InterviewPanel $target, User $actor): array
    {
        $target->loadMissing('stage');
        $out = [];
        foreach ($candidates as $c) {
            $c->loadMissing('stage', 'slot', 'application');
            $row = ['uid' => $c->uid, 'name' => $c->application->applicant->full_name, 'sent' => false, 'reason' => null, 'slot' => null];
            if ($c->status !== 'completed') {
                $row['reason'] = 'Results are not locked yet.';
            } elseif ($target->stage->course_id !== $c->stage->course_id || $target->stage->sort_order <= $c->stage->sort_order) {
                $row['reason'] = "{$target->name} is not a later stage of this course.";
            } elseif ($target->stage->min_previous_average !== null
                && self::cents($this->result($c)['average']) < self::cents($target->stage->min_previous_average)) {
                $row['reason'] = "{$c->stage->code} average {$this->result($c)['average']} is below the minimum {$target->stage->min_previous_average} for {$target->stage->code}.";
            } else {
                $slots = InterviewSlot::where('interview_panel_id', $target->id)->where('starts_at', '>=', $c->slot->ends_at)->orderBy('starts_at')->get();
                foreach ($slots as $slot) {
                    try {
                        $this->panels->schedule($c->application, $slot, $actor);
                        [$row['sent'], $row['slot']] = [true, $slot->starts_at->setTimezone(config('app.display_timezone'))->format('g:i A')];
                        break;
                    } catch (ValidationException $e) {
                        $row['reason'] = collect($e->errors())->flatten()->first();
                    }
                }
                if (! $row['sent']) {
                    $row['reason'] ??= "{$target->name} has no free slot after this candidate's {$c->stage->code}.";
                    if (str_contains((string) $row['reason'], 'full')) {
                        $row['reason'] = "{$target->name} has no free slot after this candidate's {$c->stage->code}.";
                    }
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Earlier stages' averages, shown to this panel only if its stage allows it. */
    public function previousResults(InterviewCandidate $c): array
    {
        if (! $c->stage->show_previous_marks) {
            return [];
        }
        $earlier = SelectionStage::where(['course_id' => $c->stage->course_id, 'admission_cycle_id' => $c->stage->admission_cycle_id])
            ->where('sort_order', '<', $c->stage->sort_order)->pluck('id');

        return InterviewCandidate::with('stage')->where('application_id', $c->application_id)->where('admission_round_id', $c->admission_round_id)
            ->whereIn('selection_stage_id', $earlier)->where('status', 'completed')->get()
            ->map(fn ($p) => ['stage' => $p->stage->code, 'average' => $this->result($p)['average']])->values()->all();
    }

    public function mayManage(User $user, InterviewCandidate $c): bool
    {
        return PanelAccess::isMember($user, $c->slot->panel, 'chair')
            || $this->access->can($user, 'evaluations.reopen', $c->application->scope());
    }

    private function ensureCanMark(InterviewCandidate $c, User $user): void
    {
        if (! $this->canEvaluate($user, $c)) {
            throw new NotFoundHttpException;
        }
        if (! in_array($c->status, ['present'], true)) {
            throw ValidationException::withMessages(['candidate' => $c->status === 'completed' ? 'Results are locked.' : 'Marks can be entered only for a candidate marked present.']);
        }
    }

    private function history(InterviewEvaluation $ev, string $event, ?string $reason, User $actor): void
    {
        EvaluationScoreHistory::create([
            'interview_evaluation_id' => $ev->id, 'event' => $event,
            'snapshot' => ['remarks' => $ev->remarks, 'scores' => $ev->scores()->pluck('marks', 'evaluation_criterion_id')],
            'reason' => $reason, 'actor_id' => $actor->id, 'occurred_at' => now(),
        ]);
    }
}

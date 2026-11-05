<?php

namespace App\Modules\Selection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\InterviewEvaluation;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Models\InterviewSlot;
use App\Modules\Selection\Services\EvaluationService;
use App\Modules\Selection\Services\PanelAccess;
use App\Modules\Selection\Services\PanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** "My interviews": the panel member's day, the marking screen (GD group or PI single), autosave and submit. */
class EvaluationController extends Controller
{
    public function __construct(private EvaluationService $evaluations) {}

    /** Panels the user sits on for a date (default today in IST), with slots and candidates. */
    public function myDay(Request $request): JsonResponse
    {
        $tz = config('app.display_timezone');
        $date = $request->query('date') ?: now($tz)->toDateString();
        $user = $this->actor();
        $panels = InterviewPanel::query()->with('stage', 'location', 'round:id,uid,name')
            ->whereHas('members', fn ($m) => $m->where('user_id', $user->id)->whereNull('removed_at'))
            ->whereDate('panel_date', $date)->where('status', 'active')->orderBy('name')->get();

        $upcoming = InterviewPanel::query()->whereHas('members', fn ($m) => $m->where('user_id', $user->id)->whereNull('removed_at'))
            ->where('status', 'active')->whereDate('panel_date', '>=', now($tz)->toDateString())
            ->orderBy('panel_date')->pluck('panel_date')->map(fn ($d) => $d->toDateString())->unique()->values();

        $data = $panels->map(function (InterviewPanel $p) use ($user, $tz) {
            $role = $p->members()->where('user_id', $user->id)->whereNull('removed_at')->value('role');
            $slots = $p->slots()->orderBy('starts_at')
                ->with(['candidates' => fn ($q) => $q->where('active', true)->orderBy('id')->with('application.applicant:id,full_name', 'evaluations')])->get();

            return $p->only(['uid', 'name', 'mode', 'venue', 'meeting_link']) + [
                'role' => $role, 'stage' => $p->stage->only(['uid', 'code', 'name']), 'round' => $p->round->only(['uid', 'name']),
                'location' => $p->location?->only(['name', 'city', 'address']),
                'slots' => $slots->map(fn ($s) => [
                    'uid' => $s->uid, 'time' => $s->starts_at->setTimezone($tz)->format('g:i A').'–'.$s->ends_at->setTimezone($tz)->format('g:i A'),
                    'candidates' => $s->candidates->map(function ($c) use ($user) {
                        $mine = $c->evaluations->firstWhere('evaluator_id', $user->id);

                        return ['uid' => $c->uid, 'status' => $c->status, 'name' => $c->application->applicant->full_name,
                            'portal_application_no' => $c->application->portal_application_no,
                            'my_status' => $mine?->status, 'conflict' => PanelAccess::hasConflict($user, $c)];
                    }),
                ]),
            ];
        });

        return response()->json(['data' => ['date' => $date, 'panels' => $data, 'dates' => $upcoming]]);
    }

    /** The marking screen for one slot: every candidate in it (a GD group, or one PI candidate). */
    public function slot(string $slotUid): JsonResponse
    {
        $candidates = InterviewCandidate::with('slot.panel.stage', 'application.applicant', 'application.course:id,code,name')
            ->whereHas('slot', fn ($s) => $s->where('uid', $slotUid))->where('active', true)->orderBy('id')->get();
        $slot = $candidates->first()?->slot ?? InterviewSlot::where('uid', $slotUid)->firstOrFail();
        $panel = $slot->panel;
        if (! PanelAccess::isMember($this->actor(), $panel) && ! $this->viewer($panel)) {
            throw new NotFoundHttpException;
        }
        $stage = $panel->stage;
        $criteria = $stage->criteria()->where('status', 'active')->orderBy('sort_order')->get(['id', 'name', 'max_marks']);
        $tz = config('app.display_timezone');

        return response()->json(['data' => [
            'panel' => $panel->only(['uid', 'name', 'mode', 'meeting_link']) + ['role' => $panel->members()->where('user_id', $this->actor()->id)->whereNull('removed_at')->value('role'),
                'has_chair' => $panel->members()->whereNull('removed_at')->where('role', 'chair')->exists()],
            'slot' => ['uid' => $slot->uid, 'time' => $slot->starts_at->setTimezone($tz)->format('g:i A').'–'.$slot->ends_at->setTimezone($tz)->format('g:i A')],
            'stage' => $stage->only(['uid', 'code', 'name', 'remark_rule']) + ['total_max' => EvaluationService::fromCents($criteria->sum(fn ($c) => EvaluationService::cents($c->max_marks)))],
            'criteria' => $criteria,
            'candidates' => $candidates->map(fn ($c) => $this->candidate($c)),
        ]]);
    }

    public function save(Request $request, InterviewCandidate $candidate): JsonResponse
    {
        $data = $request->validate(['marks' => ['present', 'array'], 'remarks' => ['nullable', 'string', 'max:2000']]);
        $ev = $this->evaluations->save($candidate, $this->actor(), $data['marks'], $request->has('remarks') ? (string) ($data['remarks'] ?? '') : null);

        return response()->json(['data' => $this->mine($ev), 'saved_at' => now()->toIso8601String()]);
    }

    public function submit(InterviewCandidate $candidate): JsonResponse
    {
        $ev = $this->evaluations->submit($candidate, $this->actor());

        return response()->json(['data' => $this->candidate($candidate->fresh(['slot.panel.stage', 'application.applicant', 'application.course'])), 'evaluation' => $this->mine($ev)]);
    }

    public function reopen(Request $request, InterviewEvaluation $evaluation): JsonResponse
    {
        $panel = $evaluation->candidate->slot->panel;
        if (! PanelAccess::isMember($this->actor(), $panel) && ! $this->viewer($panel)) {
            throw new NotFoundHttpException;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->evaluations->reopen($evaluation, $data['reason'], $this->actor());

        return response()->json(['message' => 'Reopened. The panel member can correct and submit again.']);
    }

    private function candidate(InterviewCandidate $c): array
    {
        $user = $this->actor();
        $mine = InterviewEvaluation::with('scores')->where(['interview_candidate_id' => $c->id, 'evaluator_id' => $user->id])->first();
        $panel = $c->slot->panel;
        $seeOthers = ($mine?->status === 'submitted') || $this->viewer($panel);
        $app = $c->application;

        return [
            'uid' => $c->uid, 'status' => $c->status, 'locked' => $c->result_locked_at !== null,
            'application' => ['uid' => $app->uid, 'portal_application_no' => $app->portal_application_no, 'name' => $app->applicant->full_name,
                'course' => $app->course?->code, 'category' => $app->category],
            'can_mark' => $this->evaluations->canEvaluate($user, $c),
            'conflict' => PanelAccess::hasConflict($user, $c),
            'mine' => $mine ? $this->mine($mine) : null,
            'previous' => $this->evaluations->previousResults($c),
            'result' => $seeOthers ? $this->evaluations->result($c) : null,
            'expected' => count($this->evaluations->evaluators($c)),
        ];
    }

    private function mine(InterviewEvaluation $ev): array
    {
        return ['uid' => $ev->uid, 'status' => $ev->status, 'remarks' => $ev->remarks, 'reopen_reason' => $ev->reopen_reason,
            'scores' => $ev->scores->mapWithKeys(fn ($s) => [$s->evaluation_criterion_id => $s->marks]),
            'total' => EvaluationService::fromCents($ev->scores->sum(fn ($s) => EvaluationService::cents($s->marks)))];
    }

    private function viewer(InterviewPanel $panel): bool
    {
        return $this->access()->can($this->actor(), 'evaluations.view', PanelService::scope($panel));
    }
}

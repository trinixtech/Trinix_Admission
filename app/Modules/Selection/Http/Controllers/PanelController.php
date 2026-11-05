<?php

namespace App\Modules\Selection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\User;
use App\Modules\Applicants\Models\Application;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Organisation\Models\Location;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Models\InterviewPanelMember;
use App\Modules\Selection\Models\InterviewSlot;
use App\Modules\Selection\Models\SelectionStage;
use App\Modules\Selection\Services\EvaluationService;
use App\Modules\Selection\Services\PanelAccess;
use App\Modules\Selection\Services\PanelService;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PanelController extends Controller
{
    public function __construct(private PanelService $panels, private EvaluationService $evaluations) {}

    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'panels.manage');
        $q = InterviewPanel::query()->join('selection_stages as st', 'st.id', '=', 'interview_panels.selection_stage_id')
            ->select('interview_panels.*')->with('stage:id,uid,code,name,course_id', 'location:id,uid,name,city', 'members.user:id,uid,name')
            ->withCount('slots')
            ->when($request->query('date'), fn ($w, $d) => $w->whereDate('panel_date', $d))
            ->when($request->query('round_uid'), fn ($w, $r) => $w->where('admission_round_id', AdmissionRound::where('uid', $r)->value('id') ?? 0))
            ->orderBy('panel_date')->orderBy('interview_panels.name');
        $this->access()->applyScope($q, $this->actor(), 'panels.manage', ['course' => 'st.course_id', 'cycle' => 'st.admission_cycle_id'], 'covers');

        return response()->json($q->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'panels.manage');
        $data = $this->validated($request);
        $stage = SelectionStage::where('uid', $data['stage_uid'])->firstOrFail();
        $round = AdmissionRound::where('uid', $data['round_uid'])->firstOrFail();
        if ($round->admission_cycle_id !== $stage->admission_cycle_id) {
            $this->fail('round_uid', 'The round belongs to another cycle.');
        }
        $this->access()->authorize($this->actor(), 'panels.manage', new Scope(null, $stage->course_id, $stage->admission_cycle_id));
        $panel = DB::transaction(function () use ($data, $stage, $round) {
            $p = InterviewPanel::create([
                'admission_round_id' => $round->id, 'selection_stage_id' => $stage->id, 'name' => $data['name'], 'panel_date' => $data['panel_date'],
                'mode' => $data['mode'], 'location_id' => ! empty($data['location_uid']) ? Location::where('uid', $data['location_uid'])->value('id') : null,
                'venue' => $data['venue'] ?? null, 'meeting_link' => $data['meeting_link'] ?? null, 'status' => 'active',
            ]);
            $this->audit()->created('panel.created', $p, PanelService::scope($p));

            return $p;
        });

        return response()->json(['data' => $this->present($panel)], 201);
    }

    public function show(InterviewPanel $panel): JsonResponse
    {
        $this->ensureVisible($panel);

        return response()->json(['data' => $this->present($panel)]);
    }

    public function update(Request $request, InterviewPanel $panel): JsonResponse
    {
        $this->ensureManage($panel);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'venue' => ['nullable', 'string', 'max:255'],
            'meeting_link' => ['nullable', 'url:https', 'max:500'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        DB::transaction(fn () => $this->audit()->update('panel.updated', $panel, $data, PanelService::scope($panel)));

        return response()->json(['data' => $this->present($panel)]);
    }

    public function addMember(Request $request, InterviewPanel $panel): JsonResponse
    {
        $this->ensureManage($panel);
        $data = $request->validate(['user_uid' => ['required', Rule::exists('users', 'uid')], 'role' => ['required', Rule::in(['chair', 'member'])]]);
        $this->panels->addMember($panel, User::where('uid', $data['user_uid'])->firstOrFail(), $data['role'], $this->actor());

        return response()->json(['data' => $this->present($panel)], 201);
    }

    /** People who may sit on this panel: active users who can mark candidates of its course and cycle. */
    public function candidatesForMembership(Request $request, InterviewPanel $panel): JsonResponse
    {
        $this->ensureManage($panel);
        $scope = PanelService::scope($panel);
        $term = trim((string) $request->query('q', ''));
        $users = User::query()->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$term%")->orWhere('email', 'like', "%$term%")))
            ->orderBy('name')->limit(200)->get()
            ->filter(fn (User $u) => $this->access()->can($u, 'evaluations.submit', $scope))->take(30)
            ->map(fn (User $u) => $u->only(['uid', 'name', 'email']))->values();

        return response()->json(['data' => $users]);
    }

    public function removeMember(InterviewPanelMember $member): JsonResponse
    {
        $this->ensureManage($member->panel);
        $this->panels->removeMember($member, $this->actor());

        return response()->json(['data' => $this->present($member->panel)]);
    }

    public function addSlot(Request $request, InterviewPanel $panel): JsonResponse
    {
        $this->ensureManage($panel);
        $data = $request->validate(['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date'], 'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'repeat' => ['nullable', 'integer', 'min:1', 'max:40']]);
        $start = PanelService::localTime($data['starts_at']);
        $end = PanelService::localTime($data['ends_at']);
        $len = $start->diffInMinutes($end);
        for ($i = 0; $i < ($data['repeat'] ?? 1); $i++) {
            $this->panels->addSlot($panel, $start->addMinutes($len * $i), $end->addMinutes($len * $i), $data['capacity']);
        }

        return response()->json(['data' => $this->present($panel)], 201);
    }

    public function schedule(Request $request, InterviewSlot $slot): JsonResponse
    {
        $this->ensureManage($slot->panel);
        $data = $request->validate(['application_uid' => ['required', Rule::exists('applications', 'uid')]]);
        $app = Application::where('uid', $data['application_uid'])->firstOrFail();
        $this->panels->schedule($app, $slot, $this->actor());

        return response()->json(['data' => $this->present($slot->panel), 'warning' => PanelService::priorMarkersWarning($app, $slot->panel)], 201);
    }

    public function bulkSchedule(Request $request, InterviewPanel $panel): JsonResponse
    {
        $this->ensureManage($panel);
        $data = $request->validate(['city' => ['nullable', 'string', 'max:100']]);
        $n = $this->panels->bulkSchedule($panel->slots()->orderBy('starts_at')->get()->all(), $data['city'] ?? null, $this->actor());

        return response()->json(['message' => "$n candidates scheduled. Send the schedule emails when the schedule is final.", 'data' => $this->present($panel)]);
    }

    public function reschedule(Request $request, InterviewCandidate $candidate): JsonResponse
    {
        $this->ensureManage($candidate->slot->panel);
        $data = $request->validate(['slot_uid' => ['required', Rule::exists('interview_slots', 'uid')], 'reason' => ['required', 'string', 'max:1000']]);
        $slot = InterviewSlot::where('uid', $data['slot_uid'])->firstOrFail();
        $this->ensureManage($slot->panel);
        $this->panels->reschedule($candidate, $slot, $data['reason'], $this->actor());

        return response()->json(['data' => $this->present($slot->panel), 'warning' => PanelService::priorMarkersWarning($candidate->application, $slot->panel)]);
    }

    public function cancel(Request $request, InterviewCandidate $candidate): JsonResponse
    {
        $this->ensureManage($candidate->slot->panel);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->panels->cancel($candidate, $data['reason'], $this->actor());

        return response()->json(['data' => $this->present($candidate->slot->panel)]);
    }

    /** Attendance: admission staff with panels.manage, or the panel's chair. */
    public function attendance(Request $request, InterviewCandidate $candidate): JsonResponse
    {
        $panel = $candidate->slot->panel;
        // The chair marks attendance; on a panel without a chair (D26), any current member may.
        $hasChair = $panel->members()->whereNull('removed_at')->where('role', 'chair')->exists();
        if (! PanelAccess::isMember($this->actor(), $panel, $hasChair ? 'chair' : null)) {
            $this->ensureManage($panel);
        }
        $data = $request->validate(['present' => ['required', 'boolean']]);
        $this->panels->attendance($candidate, $data['present'], $this->actor());

        return response()->json(['data' => $candidate->fresh()->only(['uid', 'status'])]);
    }

    /** Panel summary: per candidate, each evaluator's total, the average and outlier flags. */
    public function summary(InterviewPanel $panel): JsonResponse
    {
        $chair = PanelAccess::isMember($this->actor(), $panel, 'chair');
        if (! $chair) {
            $this->access()->authorizeAnywhere($this->actor(), 'evaluations.view');
            if (! $this->access()->can($this->actor(), 'evaluations.view', PanelService::scope($panel))) {
                throw new NotFoundHttpException;
            }
        }
        $rows = InterviewCandidate::with('application.applicant', 'slot', 'stage')->whereIn('interview_slot_id', $panel->slots()->pluck('id'))
            ->where('active', true)->get()->sortBy(fn ($c) => [$c->slot->starts_at, $c->id])->values()
            ->map(fn ($c) => [
                'uid' => $c->uid, 'name' => $c->application->applicant->full_name, 'portal_application_no' => $c->application->portal_application_no,
                'status' => $c->status, 'starts_at' => $c->slot->starts_at->toIso8601String(), 'expected' => count($this->evaluations->evaluators($c)),
                'result' => $this->evaluations->result($c),
            ]);

        return response()->json(['data' => ['panel' => $this->present($panel), 'candidates' => $rows, 'can_lock' => $chair || $this->access()->can($this->actor(), 'evaluations.reopen', PanelService::scope($panel))]]);
    }

    public function lock(InterviewCandidate $candidate): JsonResponse
    {
        $this->ensureVisible($candidate->slot->panel);
        $this->evaluations->lock($candidate, $this->actor());

        return response()->json(['data' => $candidate->fresh()->only(['uid', 'status', 'result_locked_at'])]);
    }

    /** "Fix, then send": email the booked candidates of a stage (or one panel) who have not been told their slot yet. */
    public function notify(Request $request): JsonResponse
    {
        [$stage, $round, $panel] = $this->stageRound($request);
        $this->access()->authorize($this->actor(), 'panels.manage', new Scope(null, $stage->course_id, $stage->admission_cycle_id));
        $n = $this->panels->notifyPending($stage->id, $round->id, $panel?->id, $this->actor());

        return response()->json(['message' => "$n candidates were emailed their schedule.", 'emailed' => $n]);
    }

    /** The admin locks every complete result of a stage at once (optionally one panel or one day). */
    public function lockMany(Request $request): JsonResponse
    {
        [$stage, $round, $panel] = $this->stageRound($request);
        $this->access()->authorize($this->actor(), 'evaluations.lock', new Scope(null, $stage->course_id, $stage->admission_cycle_id), 'panels.manage');
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? null;
        $r = $this->evaluations->lockMany($stage->id, $round->id, $panel?->id, $date, $this->actor());

        return response()->json(['data' => $r, 'message' => "{$r['locked']} results locked".($r['skipped'] ? ', '.count($r['skipped']).' not yet complete.' : '.')]);
    }

    /** @return array{0: SelectionStage, 1: AdmissionRound, 2: ?InterviewPanel} */
    private function stageRound(Request $request): array
    {
        $d = $request->validate(['stage_uid' => ['required', Rule::exists('selection_stages', 'uid')], 'round_uid' => ['required', Rule::exists('admission_rounds', 'uid')],
            'panel_uid' => ['nullable', Rule::exists('interview_panels', 'uid')]]);

        return [SelectionStage::where('uid', $d['stage_uid'])->firstOrFail(), AdmissionRound::where('uid', $d['round_uid'])->firstOrFail(),
            isset($d['panel_uid']) ? InterviewPanel::where('uid', $d['panel_uid'])->firstOrFail() : null];
    }

    /** Send locked candidates to a later stage's panel (e.g. from GD to PI). */
    public function sendOn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'candidate_uids' => ['required', 'array', 'min:1', 'max:100'], 'candidate_uids.*' => ['string'],
            'panel_uid' => ['required', Rule::exists('interview_panels', 'uid')],
        ]);
        $target = InterviewPanel::where('uid', $data['panel_uid'])->firstOrFail();
        $candidates = InterviewCandidate::with('slot.panel')->whereIn('uid', $data['candidate_uids'])->get();
        foreach ($candidates as $c) {
            if (! PanelAccess::isMember($this->actor(), $c->slot->panel, 'chair')) {
                $this->ensureManage($c->slot->panel);
            }
        }
        $result = $this->evaluations->sendOn($candidates->all(), $target, $this->actor());

        return response()->json(['data' => $result]);
    }

    public function swap(Request $request): JsonResponse
    {
        $data = $request->validate(['a_uid' => ['required', 'string'], 'b_uid' => ['required_without:b_application_no', 'nullable', 'string'],
            'b_application_no' => ['nullable', 'string'], 'reason' => ['required', 'string', 'max:1000']]);
        $a = InterviewCandidate::where('uid', $data['a_uid'])->firstOrFail();
        $b = ! empty($data['b_uid']) ? InterviewCandidate::where('uid', $data['b_uid'])->firstOrFail()
            : InterviewCandidate::where(['selection_stage_id' => $a->selection_stage_id, 'admission_round_id' => $a->admission_round_id, 'active' => true])
                ->whereHas('application', fn ($q) => $q->where('portal_application_no', $data['b_application_no']))->first()
                ?? $this->fail('b_application_no', 'That applicant is not scheduled for this stage.');
        $this->ensureManage($a->slot->panel);
        $this->ensureManage($b->slot->panel);
        $this->panels->swap($a, $b, $data['reason'], $this->actor());

        return response()->json(['message' => 'Swapped. Both applicants have been emailed their new time.']);
    }

    public function conflict(Request $request, InterviewCandidate $candidate): JsonResponse
    {
        $panel = $candidate->slot->panel;
        if (! PanelAccess::isMember($this->actor(), $panel)) {
            throw new NotFoundHttpException;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->panels->declareConflict($panel, $this->actor(), $candidate->application, $data['reason']);

        return response()->json(['message' => 'Recorded. You will not mark this candidate; the admission office has been told to move them if needed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'stage_uid' => ['required', Rule::exists('selection_stages', 'uid')], 'round_uid' => ['required', Rule::exists('admission_rounds', 'uid')],
            'name' => ['required', 'string', 'max:255'], 'panel_date' => ['required', 'date'], 'mode' => ['required', Rule::in(['in_person', 'online'])],
            'location_uid' => ['nullable', 'required_if:mode,in_person', Rule::exists('locations', 'uid')], 'venue' => ['nullable', 'string', 'max:255'],
            'meeting_link' => ['nullable', 'required_if:mode,online', 'url:https', 'max:500'],
        ]);
    }

    private function ensureVisible(InterviewPanel $panel): void
    {
        if (PanelAccess::isMember($this->actor(), $panel)) {
            return;
        }
        $this->access()->authorizeAnywhere($this->actor(), 'panels.manage');
        if (! $this->access()->can($this->actor(), 'panels.manage', PanelService::scope($panel))) {
            throw new NotFoundHttpException;
        }
    }

    private function ensureManage(InterviewPanel $panel): void
    {
        $this->access()->authorizeAnywhere($this->actor(), 'panels.manage');
        if (! $this->access()->can($this->actor(), 'panels.manage', PanelService::scope($panel))) {
            throw new NotFoundHttpException;
        }
    }

    private function present(InterviewPanel $panel): array
    {
        $panel->refresh()->load('stage', 'location', 'round:id,uid,name');
        $tz = config('app.display_timezone');

        return $panel->only(['uid', 'name', 'mode', 'venue', 'meeting_link', 'status']) + [
            'panel_date' => $panel->panel_date->toDateString(),
            'stage' => $panel->stage->only(['uid', 'code', 'name']),
            'round' => $panel->round->only(['uid', 'name']),
            'location' => $panel->location?->only(['uid', 'name', 'city', 'address']),
            'members' => $panel->members()->whereNull('removed_at')->with('user:id,uid,name')->get()->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'user' => $m->user->only(['uid', 'name'])]),
            'slots' => $panel->slots()->orderBy('starts_at')->with(['candidates' => fn ($q) => $q->where('active', true)->with('application.applicant:id,full_name,city')])->get()
                ->map(fn ($s) => [
                    'uid' => $s->uid, 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                    'time' => $s->starts_at->setTimezone($tz)->format('g:i A').'–'.$s->ends_at->setTimezone($tz)->format('g:i A'),
                    'capacity' => $s->capacity,
                    'candidates' => $s->candidates->map(fn ($c) => ['uid' => $c->uid, 'status' => $c->status, 'notified' => (bool) $c->notified_at, 'application_uid' => $c->application->uid,
                        'portal_application_no' => $c->application->portal_application_no, 'name' => $c->application->applicant->full_name, 'city' => $c->application->applicant->city]),
                ]),
        ];
    }
}

<?php

namespace App\Modules\Cycles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Cycles\Models\AdmissionRoundOffering;
use App\Modules\Cycles\Models\CourseOffering;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdmissionRoundController extends Controller
{
    public function index(AdmissionCycle $admissionCycle): JsonResponse
    {
        $this->access()->authorizeSee($this->actor(), 'cycles.view', Scope::cycle($admissionCycle->id));

        $rounds = $admissionCycle->rounds()->with('closer:id,uid,name')->withCount('roundOfferings')->orderBy('number')->get();

        return response()->json(['data' => $rounds]);
    }

    public function show(AdmissionRound $round): JsonResponse
    {
        $this->access()->authorizeSee($this->actor(), 'cycles.view', Scope::cycle($round->admission_cycle_id));

        return response()->json(['data' => $this->present($round)]);
    }

    /** The first round of a cycle is the initial round; every later one is a fresh round. */
    public function store(Request $request, AdmissionCycle $admissionCycle): JsonResponse
    {
        $scope = Scope::cycle($admissionCycle->id);
        $this->access()->authorize($this->actor(), 'cycles.manage', $scope, 'cycles.view');
        AdmissionCycleController::ensureNotClosed($admissionCycle);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $round = DB::transaction(function () use ($admissionCycle, $data, $scope) {
            $last = $admissionCycle->rounds()->lockForUpdate()->max('number');
            $number = ($last ?? 0) + 1;
            $round = AdmissionRound::create([
                'admission_cycle_id' => $admissionCycle->id,
                'number' => $number,
                'type' => $number === 1 ? AdmissionRound::INITIAL : AdmissionRound::FRESH,
                'name' => $data['name'],
                'status' => AdmissionRound::DRAFT,
            ]);
            $this->audit()->created('round.created', $round, $scope);

            return $round;
        });

        return response()->json(['data' => $this->present($round)], 201);
    }

    public function update(Request $request, AdmissionRound $round): JsonResponse
    {
        $scope = Scope::cycle($round->admission_cycle_id);
        $this->access()->authorize($this->actor(), 'cycles.manage', $scope, 'cycles.view');
        $this->ensureEditable($round);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        DB::transaction(fn () => $this->audit()->update('round.updated', $round, $data, $scope));

        return response()->json(['data' => $this->present($round)]);
    }

    public function activate(AdmissionRound $round): JsonResponse
    {
        $scope = Scope::cycle($round->admission_cycle_id);
        $this->access()->authorize($this->actor(), 'cycles.manage', $scope, 'cycles.view');
        $this->ensureEditable($round);
        if ($round->status !== AdmissionRound::DRAFT) {
            $this->fail('status', 'Only a draft round can be activated.');
        }
        if (! $round->roundOfferings()->exists()) {
            $this->fail('status', 'Add course offerings and their dates before activating the round.');
        }
        if ($round->cycle->status !== AdmissionCycle::OPEN) {
            $this->fail('status', 'Open the cycle before activating a round.');
        }

        DB::transaction(fn () => $this->audit()->update('round.activated', $round, ['status' => AdmissionRound::ACTIVE], $scope));

        return response()->json(['data' => $this->present($round)]);
    }

    /**
     * Set the offerings in a round and their branch-wise dates.
     * Draft rounds may drop offerings; active rounds may only add offerings or change dates.
     */
    public function updateOfferings(Request $request, AdmissionRound $round): JsonResponse
    {
        $scope = Scope::cycle($round->admission_cycle_id);
        $this->access()->authorizeSee($this->actor(), 'cycles.view', $scope);
        $this->access()->authorizeAnywhere($this->actor(), 'cycles.manage');
        $this->ensureEditable($round);

        $data = $request->validate([
            'offerings' => ['present', 'array', 'max:500'],
            'offerings.*.offering_uid' => ['required', 'string', 'distinct', Rule::exists('course_offerings', 'uid')],
            'offerings.*.starts_on' => ['required', 'date'],
            'offerings.*.ends_on' => ['required', 'date'],
        ]);

        $year = $round->cycle->academicYear;
        $offerings = CourseOffering::with('branchCourse')
            ->whereIn('uid', collect($data['offerings'])->pluck('offering_uid'))
            ->get()->keyBy('uid');

        $errors = [];
        foreach ($data['offerings'] as $i => $row) {
            $offering = $offerings[$row['offering_uid']];
            if ($offering->admission_cycle_id !== $round->admission_cycle_id) {
                $errors["offerings.$i.offering_uid"] = 'This offering belongs to another cycle.';

                continue;
            }
            if (! $this->access()->can($this->actor(), 'cycles.manage', $offering->scope())) {
                abort(403, 'One of these offerings is outside your assigned scope.');
            }
            $starts = $row['starts_on'];
            $ends = $row['ends_on'];
            if ($ends < $starts) {
                $errors["offerings.$i.ends_on"] = 'The end date must be on or after the start date.';
            } elseif ($ends > $year->ends_on->toDateString()) {
                // Admissions for an academic year run before it starts (e.g. from December 2026 for 2027-28),
                // so rounds may start earlier; they must finish by the end of the academic year (D27).
                $errors["offerings.$i.ends_on"] = "The round must end by the end of the academic year {$year->code} ({$year->ends_on->toDateString()}).";
            } elseif ($this->overlapsAnotherRound($round, $offering->id, $starts, $ends)) {
                $errors["offerings.$i.starts_on"] = 'These dates overlap another round for the same offering.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $keepIds = $offerings->pluck('id')->all();
        $dropping = $round->roundOfferings()->whereNotIn('course_offering_id', $keepIds)->get();
        if ($dropping->isNotEmpty() && $round->status !== AdmissionRound::DRAFT) {
            // Removing offerings is only allowed while the round is a draft.
            $this->fail('offerings', 'Offerings cannot be removed from an active round.');
        }
        foreach ($dropping as $ro) {
            if (! $this->access()->can($this->actor(), 'cycles.manage', $ro->offering->scope())) {
                abort(403, 'One of these offerings is outside your assigned scope.');
            }
        }

        DB::transaction(function () use ($round, $data, $offerings, $dropping) {
            foreach ($dropping as $ro) {
                $this->audit()->record('round.offering_removed', $round, $ro->only(['course_offering_id', 'starts_on', 'ends_on']), null, scope: $ro->offering->scope());
                $ro->delete();
            }
            foreach ($data['offerings'] as $row) {
                $offering = $offerings[$row['offering_uid']];
                $existing = AdmissionRoundOffering::where(['admission_round_id' => $round->id, 'course_offering_id' => $offering->id])->first();
                $values = ['starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on']];
                if ($existing) {
                    $this->audit()->update('round.offering_dates_changed', $existing, $values, $offering->scope());
                } else {
                    $ro = AdmissionRoundOffering::create($values + [
                        'admission_round_id' => $round->id,
                        'course_offering_id' => $offering->id,
                        'status' => 'active',
                    ]);
                    $this->audit()->created('round.offering_added', $ro, $offering->scope());
                }
            }
        });

        return response()->json(['data' => $this->present($round)]);
    }

    public function close(Request $request, AdmissionRound $round): JsonResponse
    {
        $scope = Scope::cycle($round->admission_cycle_id);
        $this->access()->authorize($this->actor(), 'rounds.close', $scope, 'cycles.view');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted']]);
        if ($round->isClosed()) {
            $this->fail('status', 'This round is already closed.');
        }

        DB::transaction(fn () => $this->audit()->update('round.closed', $round, [
            'status' => AdmissionRound::CLOSED,
            'closed_at' => now(),
            'closed_by' => $this->actor()->id,
            'close_reason' => $data['reason'],
        ], $scope, $data['reason']));

        return response()->json(['data' => $this->present($round)]);
    }

    public function reopen(Request $request, AdmissionRound $round): JsonResponse
    {
        $scope = Scope::cycle($round->admission_cycle_id);
        $this->access()->authorize($this->actor(), 'rounds.reopen', $scope, 'cycles.view');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        if (! $round->isClosed()) {
            $this->fail('status', 'Only a closed round can be reopened.');
        }
        AdmissionCycleController::ensureNotClosed($round->cycle);

        DB::transaction(fn () => $this->audit()->update('round.reopened', $round, [
            'status' => AdmissionRound::ACTIVE,
            'closed_at' => null,
            'closed_by' => null,
            'close_reason' => null,
        ], $scope, $data['reason']));

        return response()->json(['data' => $this->present($round)]);
    }

    private function ensureEditable(AdmissionRound $round): void
    {
        AdmissionCycleController::ensureNotClosed($round->cycle);
        if ($round->isClosed()) {
            abort(409, 'This round is closed and read-only.');
        }
    }

    private function overlapsAnotherRound(AdmissionRound $round, int $offeringId, string $starts, string $ends): bool
    {
        return AdmissionRoundOffering::where('course_offering_id', $offeringId)
            ->where('admission_round_id', '!=', $round->id)
            ->whereDate('starts_on', '<=', $ends)
            ->whereDate('ends_on', '>=', $starts)
            ->exists();
    }

    private function present(AdmissionRound $round): array
    {
        $round->refresh()->load('closer:id,uid,name');
        $rows = AdmissionRoundOffering::query()
            ->where('admission_round_id', $round->id)
            ->join('course_offerings', 'course_offerings.id', '=', 'admission_round_offerings.course_offering_id')
            ->join('branch_courses', 'branch_courses.id', '=', 'course_offerings.branch_course_id')
            ->join('branches', 'branches.id', '=', 'branch_courses.branch_id')
            ->join('courses', 'courses.id', '=', 'branch_courses.course_id')
            ->select('admission_round_offerings.*', 'course_offerings.uid as offering_uid',
                'branches.name as branch_name', 'courses.name as course_name', 'courses.code as course_code')
            ->orderBy('branches.name')->orderBy('courses.name');
        $this->access()->applyScope($rows, $this->actor(), 'cycles.view', [
            'branch' => 'branch_courses.branch_id', 'course' => 'branch_courses.course_id',
        ]);

        return $round->toArray() + ['cycle' => $round->cycle->only(['uid', 'code', 'name', 'status'])] + ['offerings' => $rows->get()->map(fn ($r) => [
            'offering_uid' => $r->offering_uid,
            'branch_name' => $r->branch_name,
            'course_name' => $r->course_name,
            'course_code' => $r->course_code,
            'starts_on' => $r->starts_on->toDateString(),
            'ends_on' => $r->ends_on->toDateString(),
            'status' => $r->status,
        ])];
    }
}

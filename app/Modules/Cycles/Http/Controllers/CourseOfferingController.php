<?php

namespace App\Modules\Cycles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\CourseOffering;
use App\Modules\Organisation\Models\BranchCourse;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CourseOfferingController extends Controller
{
    public function index(Request $request, AdmissionCycle $admissionCycle): JsonResponse
    {
        $this->access()->authorizeSee($this->actor(), 'cycles.view', Scope::cycle($admissionCycle->id));

        $query = CourseOffering::query()
            ->where('admission_cycle_id', $admissionCycle->id)
            ->join('branch_courses', 'branch_courses.id', '=', 'course_offerings.branch_course_id')
            ->join('branches', 'branches.id', '=', 'branch_courses.branch_id')
            ->join('courses', 'courses.id', '=', 'branch_courses.course_id')
            ->select('course_offerings.*', 'branches.uid as branch_uid', 'branches.name as branch_name',
                'courses.uid as course_uid', 'courses.name as course_name', 'courses.code as course_code')
            ->orderBy('branches.name')->orderBy('courses.name');
        $this->access()->applyScope($query, $this->actor(), 'cycles.view', [
            'branch' => 'branch_courses.branch_id', 'course' => 'branch_courses.course_id', 'cycle' => 'course_offerings.admission_cycle_id',
        ]);

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, AdmissionCycle $admissionCycle): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'cycles.manage');
        $this->access()->authorizeSee($this->actor(), 'cycles.view', Scope::cycle($admissionCycle->id));
        AdmissionCycleController::ensureNotClosed($admissionCycle);
        $data = $request->validate([
            'branch_uid' => ['required', 'string', Rule::exists('branches', 'uid')],
            'course_uid' => ['required', 'string', Rule::exists('courses', 'uid')],
            'sanctioned_intake' => ['required', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in(CourseOffering::STATUSES)],
        ]);

        $branchCourse = BranchCourse::query()
            ->whereHas('branch', fn ($q) => $q->where('uid', $data['branch_uid']))
            ->whereHas('course', fn ($q) => $q->where('uid', $data['course_uid']))
            ->first();
        if (! $branchCourse || $branchCourse->status !== 'active') {
            $this->fail('course_uid', 'This branch does not offer this course. Tick it in the branch-course grid first.');
        }

        $scope = new Scope($branchCourse->branch_id, $branchCourse->course_id, $admissionCycle->id);
        $this->access()->authorize($this->actor(), 'cycles.manage', $scope, 'cycles.view');

        if (CourseOffering::where(['admission_cycle_id' => $admissionCycle->id, 'branch_course_id' => $branchCourse->id])->exists()) {
            $this->fail('course_uid', 'This branch already offers this course in this cycle.');
        }

        $offering = DB::transaction(function () use ($data, $admissionCycle, $branchCourse, $scope) {
            $offering = CourseOffering::create([
                'admission_cycle_id' => $admissionCycle->id,
                'branch_course_id' => $branchCourse->id,
                'sanctioned_intake' => $data['sanctioned_intake'],
                'status' => $data['status'] ?? 'active',
            ]);
            $this->audit()->created('offering.created', $offering, $scope);

            return $offering;
        });

        return response()->json(['data' => $offering->fresh()], 201);
    }

    public function update(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        $courseOffering->loadMissing('cycle', 'branchCourse');
        AdmissionCycleController::ensureNotClosed($courseOffering->cycle);
        $scope = $courseOffering->scope();
        $this->access()->authorize($this->actor(), 'cycles.manage', $scope, 'cycles.view');

        $data = $request->validate([
            'sanctioned_intake' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in(CourseOffering::STATUSES)],
        ]);

        DB::transaction(fn () => $this->audit()->update('offering.updated', $courseOffering, $data, $scope));

        return response()->json(['data' => $courseOffering->fresh()]);
    }
}

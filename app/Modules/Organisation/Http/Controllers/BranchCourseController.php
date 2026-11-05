<?php

namespace App\Modules\Organisation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Organisation\Models\BranchCourse;
use App\Modules\Organisation\Models\Course;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The branch-course grid: which branch may offer which course. Links are switched off, never deleted. */
class BranchCourseController extends Controller
{
    public function index(): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'organisation.view');

        $query = BranchCourse::query()
            ->join('branches', 'branches.id', '=', 'branch_courses.branch_id')
            ->join('courses', 'courses.id', '=', 'branch_courses.course_id')
            ->select('branch_courses.*', 'branches.uid as branch_uid', 'branches.name as branch_name',
                'courses.uid as course_uid', 'courses.name as course_name', 'courses.code as course_code')
            ->orderBy('branches.name')->orderBy('courses.name');
        $this->access()->applyScope($query, $this->actor(), 'organisation.view', [
            'branch' => 'branch_courses.branch_id', 'course' => 'branch_courses.course_id',
        ]);

        return response()->json(['data' => $query->get()->map(fn ($bc) => [
            'id' => $bc->id,
            'branch_uid' => $bc->branch_uid,
            'branch_name' => $bc->branch_name,
            'course_uid' => $bc->course_uid,
            'course_name' => $bc->course_name,
            'course_code' => $bc->course_code,
            'status' => $bc->status,
        ])]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'organisation.manage');
        $data = $request->validate([
            'links' => ['required', 'array', 'max:500'],
            'links.*.branch_uid' => ['required', 'string', Rule::exists('branches', 'uid')],
            'links.*.course_uid' => ['required', 'string', Rule::exists('courses', 'uid')],
            'links.*.status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $branches = Branch::whereIn('uid', collect($data['links'])->pluck('branch_uid'))->pluck('id', 'uid');
        $courses = Course::whereIn('uid', collect($data['links'])->pluck('course_uid'))->pluck('id', 'uid');

        DB::transaction(function () use ($data, $branches, $courses) {
            foreach ($data['links'] as $link) {
                $branchId = $branches[$link['branch_uid']];
                $courseId = $courses[$link['course_uid']];
                $scope = new Scope($branchId, $courseId);
                $existing = BranchCourse::where(['branch_id' => $branchId, 'course_id' => $courseId])->first();
                if ($existing) {
                    $this->audit()->update('branch_course.updated', $existing, ['status' => $link['status']], $scope);
                } elseif ($link['status'] === 'active') {
                    $bc = BranchCourse::create(['branch_id' => $branchId, 'course_id' => $courseId, 'status' => 'active']);
                    $this->audit()->created('branch_course.created', $bc, $scope);
                }
            }
        });

        return $this->index();
    }
}

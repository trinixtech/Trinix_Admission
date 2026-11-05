<?php

namespace App\Modules\Organisation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organisation\Models\Course;
use App\Modules\Organisation\Models\Institution;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'organisation.view');

        $query = Course::query()
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name');
        $this->access()->applyScope($query, $this->actor(), 'organisation.view', ['course' => 'courses.id']);

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function show(Course $course): JsonResponse
    {
        $this->access()->authorizeSee($this->actor(), 'organisation.view', new Scope(null, $course->id));

        return response()->json(['data' => $course]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'organisation.manage');
        $data = $this->validated($request);
        $institution = Institution::firstOrFail();

        $course = DB::transaction(function () use ($data, $institution) {
            $course = Course::create($data + ['institution_id' => $institution->id]);
            $this->audit()->created('course.created', $course, new Scope(null, $course->id));

            return $course;
        });

        return response()->json(['data' => $course->fresh()], 201);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $scope = new Scope(null, $course->id);
        $this->access()->authorize($this->actor(), 'organisation.manage', $scope, 'organisation.view');
        $data = $this->validated($request, $course);
        if (isset($data['code']) && $data['code'] !== $course->code && $course->isReferenced()) {
            $this->fail('code', 'The code cannot change once the course is in use.');
        }

        DB::transaction(fn () => $this->audit()->update('course.updated', $course, $data, $scope));

        return response()->json(['data' => $course->fresh()]);
    }

    private function validated(Request $request, ?Course $course = null): array
    {
        $required = $course ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:32', 'alpha_dash', Rule::unique('courses')->ignore($course?->id)],
            'name' => [$required, 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:64'],
            'level' => [$required, Rule::in(Course::LEVELS)],
            'duration_months' => [$required, 'integer', 'min:1', 'max:120'],
            'entrance_exam' => ['nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}

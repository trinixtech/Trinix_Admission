<?php

namespace App\Modules\Selection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Organisation\Models\Course;
use App\Modules\Selection\Models\EvaluationCriterion;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\SelectionStage;
use App\Modules\Selection\Services\AutoAllocation;
use App\Modules\Selection\Services\EvaluationService;
use App\Modules\Selection\Services\StageFormula;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Selection stages per course and cycle, their criteria and the admin settings for the flow between stages. */
class StageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$course, $cycle] = $this->courseCycle($request);
        $scope = new Scope(null, $course->id, $cycle->id);
        if (! $this->access()->canSee($this->actor(), 'stages.configure', $scope)) {
            $this->access()->authorizeSee($this->actor(), 'panels.manage', $scope);
        }

        return response()->json(['data' => SelectionStage::with(['criteria' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->where(['course_id' => $course->id, 'admission_cycle_id' => $cycle->id])->orderBy('sort_order')->get()]);
    }

    public function suggest(Request $request): JsonResponse
    {
        [$course, $cycle] = $this->courseCycle($request);
        $scope = new Scope(null, $course->id, $cycle->id);
        $this->access()->authorize($this->actor(), 'stages.configure', $scope);
        $n = 0;
        DB::transaction(function () use ($course, $cycle, $scope, &$n) {
            foreach (config("selection.suggested_stages.{$course->level}", []) as $i => [$code, $name]) {
                $stage = SelectionStage::firstOrNew(['course_id' => $course->id, 'admission_cycle_id' => $cycle->id, 'code' => $code]);
                if (! $stage->exists) {
                    $stage->fill(['name' => $name, 'sort_order' => $i + 1, 'remark_rule' => ['required' => 'always'], 'status' => 'active'])->save();
                    $this->audit()->created('stage.created', $stage, $scope);
                    $n++;
                }
            }
        });

        return response()->json(['message' => "$n stages added. Add each stage's criteria."] + $this->index($request)->getData(true));
    }

    public function store(Request $request): JsonResponse
    {
        [$course, $cycle] = $this->courseCycle($request);
        $scope = new Scope(null, $course->id, $cycle->id);
        $this->access()->authorize($this->actor(), 'stages.configure', $scope);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Z][A-Z0-9_]*$/', Rule::unique('selection_stages')->where('course_id', $course->id)->where('admission_cycle_id', $cycle->id)],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $stage = DB::transaction(function () use ($data, $course, $cycle, $scope) {
            $s = SelectionStage::create($data + ['course_id' => $course->id, 'admission_cycle_id' => $cycle->id,
                'sort_order' => (int) SelectionStage::where(['course_id' => $course->id, 'admission_cycle_id' => $cycle->id])->max('sort_order') + 1,
                'remark_rule' => ['required' => 'always'], 'status' => 'active']);
            $this->audit()->created('stage.created', $s, $scope);

            return $s;
        });

        return response()->json(['data' => $stage], 201);
    }

    public function update(Request $request, SelectionStage $stage): JsonResponse
    {
        $scope = new Scope(null, $stage->course_id, $stage->admission_cycle_id);
        $this->access()->authorize($this->actor(), 'stages.configure', $scope);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'remark_rule' => ['sometimes', 'array'],
            'remark_rule.required' => ['required_with:remark_rule', Rule::in(['always', 'below', 'never'])],
            'remark_rule.below' => ['nullable', 'required_if:remark_rule.required,below', 'numeric', 'min:0'],
            'outlier_threshold' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'show_previous_marks' => ['sometimes', 'boolean'],
            'min_previous_average' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'result_formula' => ['sometimes', 'string', 'max:255'],
            'formula_min_members' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'slot_order' => ['sometimes', 'nullable', 'array', 'max:3'],
            'slot_order.*.key' => ['required', Rule::in(AutoAllocation::ORDER_KEYS)],
            'slot_order.*.direction' => ['required', Rule::in(['asc', 'desc'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'criteria' => ['sometimes', 'array', 'min:1', 'max:20'],
            'criteria.*.id' => ['nullable', 'integer'],
            'criteria.*.name' => ['required', 'string', 'max:120'],
            'criteria.*.max_marks' => ['required', 'numeric', 'min:0.5', 'max:1000', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);
        if (isset($data['result_formula'])) {
            $data['result_formula'] = trim($data['result_formula']) === '' ? 'AVG' : preg_replace('/\s+/', ' ', trim($data['result_formula']));
            if ($error = StageFormula::check($data['result_formula'])) {
                $this->fail('result_formula', $error);
            }
        }
        $changesRule = (isset($data['result_formula']) && $data['result_formula'] !== $stage->result_formula)
            || (isset($data['formula_min_members']) && (int) $data['formula_min_members'] !== (int) $stage->formula_min_members);
        if ($changesRule && InterviewCandidate::where('selection_stage_id', $stage->id)->whereNotNull('result_locked_at')->exists()) {
            $this->fail('result_formula', 'Results of this stage are already locked; the formula can no longer change.');
        }
        $hasMarks = DB::table('interview_evaluation_scores')->join('evaluation_criteria', 'evaluation_criteria.id', '=', 'interview_evaluation_scores.evaluation_criterion_id')
            ->where('evaluation_criteria.selection_stage_id', $stage->id)->exists();

        DB::transaction(function () use ($stage, $data, $scope, $hasMarks) {
            $criteria = $data['criteria'] ?? null;
            unset($data['criteria']);
            $this->audit()->update('stage.updated', $stage, $data, $scope);
            if ($criteria !== null) {
                $old = $stage->criteria()->where('status', 'active')->get()->keyBy('id');
                $keep = [];
                foreach ($criteria as $i => $c) {
                    $row = isset($c['id']) ? $old->get($c['id']) : null;
                    if ($row && $hasMarks && EvaluationService::cents($row->max_marks) !== EvaluationService::cents($c['max_marks'])) {
                        $this->fail("criteria.$i.max_marks", 'Marks have been entered for this stage; its maximum cannot change.');
                    }
                    if ($row) {
                        $row->forceFill(['name' => $c['name'], 'max_marks' => $c['max_marks'], 'sort_order' => $i])->save();
                        $keep[] = $row->id;
                    } else {
                        if ($hasMarks) {
                            $this->fail("criteria.$i.name", 'Marks have been entered for this stage; criteria cannot be added.');
                        }
                        $keep[] = EvaluationCriterion::create(['selection_stage_id' => $stage->id, 'name' => $c['name'], 'max_marks' => $c['max_marks'], 'sort_order' => $i])->id;
                    }
                }
                $removed = $old->keys()->diff($keep);
                if ($removed->isNotEmpty() && $hasMarks) {
                    $this->fail('criteria', 'Marks have been entered for this stage; criteria cannot be removed.');
                }
                EvaluationCriterion::whereIn('id', $removed)->update(['status' => 'inactive']);
                $this->audit()->record('stage.criteria_changed', $stage, ['criteria' => $old->map->only(['name', 'max_marks'])->values()],
                    ['criteria' => array_map(fn ($c) => ['name' => $c['name'], 'max_marks' => $c['max_marks']], $criteria)], scope: $scope);
            }
        });

        return response()->json(['data' => $stage->fresh(['criteria' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])]);
    }

    /**
     * Copy stages, criteria and settings from another cycle of the same course into this one (D26: the admin
     * sets GD/PI criteria and maximum marks for every cycle; this gives a starting point to edit).
     */
    public function copy(Request $request): JsonResponse
    {
        [$course, $cycle] = $this->courseCycle($request);
        $scope = new Scope(null, $course->id, $cycle->id);
        $this->access()->authorize($this->actor(), 'stages.configure', $scope);
        $from = AdmissionCycle::where('uid', $request->validate(['from_cycle_uid' => ['required', Rule::exists('admission_cycles', 'uid')]])['from_cycle_uid'])->firstOrFail();
        if ($from->id === $cycle->id) {
            $this->fail('from_cycle_uid', 'Choose a different cycle to copy from.');
        }
        if (SelectionStage::where(['course_id' => $course->id, 'admission_cycle_id' => $cycle->id])->exists()) {
            $this->fail('from_cycle_uid', 'This cycle already has stages. Copying is only for a cycle without stages.');
        }
        $source = SelectionStage::with(['criteria' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->where(['course_id' => $course->id, 'admission_cycle_id' => $from->id])->orderBy('sort_order')->get();
        if ($source->isEmpty()) {
            $this->fail('from_cycle_uid', 'That cycle has no stages for this course.');
        }
        DB::transaction(function () use ($source, $cycle, $scope, $from) {
            foreach ($source as $s) {
                $copy = $s->replicate(['uid'])->fill(['admission_cycle_id' => $cycle->id]);
                $copy->save();
                foreach ($s->criteria as $c) {
                    EvaluationCriterion::create(['selection_stage_id' => $copy->id, 'name' => $c->name, 'max_marks' => $c->max_marks, 'sort_order' => $c->sort_order]);
                }
                $this->audit()->created('stage.created', $copy, $scope, "Copied from {$from->name}");
            }
        });

        return response()->json(['message' => "{$source->count()} stages copied from {$from->name}. Check the criteria and maximum marks for this cycle."] + $this->index($request)->getData(true));
    }

    /** @return array{0: Course, 1: AdmissionCycle} */
    private function courseCycle(Request $request): array
    {
        $d = $request->validate(['course_uid' => ['required', Rule::exists('courses', 'uid')], 'cycle_uid' => ['required', Rule::exists('admission_cycles', 'uid')]]);

        return [Course::where('uid', $d['course_uid'])->firstOrFail(), AdmissionCycle::where('uid', $d['cycle_uid'])->firstOrFail()];
    }
}

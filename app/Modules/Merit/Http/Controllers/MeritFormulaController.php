<?php

namespace App\Modules\Merit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applicants\Models\Application;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Imports\Services\FieldCatalogue;
use App\Modules\Merit\Models\MeritFormula;
use App\Modules\Merit\Services\MeritEngine;
use App\Modules\Merit\Services\MeritService;
use App\Modules\Organisation\Models\Course;
use App\Modules\Selection\Models\SelectionStage;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Merit formulas per course and cycle: versions, draft editing, approval with a source, one-candidate preview. */
class MeritFormulaController extends Controller
{
    public function __construct(private MeritService $merit) {}

    public function index(Request $request): JsonResponse
    {
        [$course, $cycle] = $this->courseCycle($request);
        $scope = new Scope(null, $course->id, $cycle->id);
        if (! $this->access()->canSee($this->actor(), 'merit.configure', $scope)) {
            $this->access()->authorizeSee($this->actor(), 'merit.view', $scope);
        }

        return response()->json(['data' => [
            'versions' => MeritFormula::with('components', 'approver:id,uid,name', 'creator:id,uid,name')
                ->where(['course_id' => $course->id, 'admission_cycle_id' => $cycle->id])->orderByDesc('version')->get(),
            'options' => [
                'sources' => MeritEngine::SOURCES, 'methods' => MeritEngine::METHODS, 'point_fields' => MeritEngine::POINT_FIELDS,
                'categories' => [...MeritEngine::categories(), 'PwBD'], 'academic_levels' => FieldCatalogue::ACADEMIC_LEVELS,
                'stages' => SelectionStage::where(['course_id' => $course->id, 'admission_cycle_id' => $cycle->id])->orderBy('sort_order')->get(['code', 'name']),
                'tie_keys' => ['entrance_percentile', 'entrance_score', 'date_of_birth', 'work_experience', ...array_map(fn ($l) => "academic:$l", array_keys(FieldCatalogue::ACADEMIC_LEVELS))],
            ],
        ]]);
    }

    /** A new draft version: from the body, or a copy of the latest version when the body has no components. */
    public function store(Request $request): JsonResponse
    {
        [$course, $cycle] = $this->courseCycle($request);
        $this->access()->authorize($this->actor(), 'merit.configure', new Scope(null, $course->id, $cycle->id));
        if (! $request->has('components')) {
            $latest = MeritFormula::with('components')->where(['course_id' => $course->id, 'admission_cycle_id' => $cycle->id])->orderByDesc('version')->first();
            $request->merge($latest ? self::asInput($latest) : ['name' => "{$course->code} merit", 'components' => [], 'tie_breaks' => []]);
        }
        $data = $request->validate(MeritService::formulaRules());

        return response()->json(['data' => $this->merit->saveDraft($course->id, $cycle->id, $data, $this->actor())], 201);
    }

    public function update(Request $request, MeritFormula $formula): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'merit.configure', MeritService::scope($formula), 'merit.configure');
        $data = $request->validate(MeritService::formulaRules());

        return response()->json(['data' => $this->merit->saveDraft($formula->course_id, $formula->admission_cycle_id, $data, $this->actor(), $formula)]);
    }

    public function approve(MeritFormula $formula): JsonResponse
    {
        $scope = MeritService::scope($formula);
        if (! $this->access()->canSee($this->actor(), 'merit.approve', $scope) && ! $this->access()->canSee($this->actor(), 'merit.view', $scope)) {
            throw new NotFoundHttpException;
        }
        $this->access()->authorize($this->actor(), 'merit.approve', $scope);
        $this->merit->approveFormula($formula, $this->actor());

        return response()->json(['data' => $formula->fresh(['components', 'approver:id,uid,name'])]);
    }

    /** Try the formula on one candidate (draft or approved). Nothing is saved. */
    public function preview(Request $request, MeritFormula $formula): JsonResponse
    {
        $scope = MeritService::scope($formula);
        if (! $this->access()->canSee($this->actor(), 'merit.configure', $scope)) {
            $this->access()->authorizeSee($this->actor(), 'merit.view', $scope);
        }
        $d = $request->validate(['application_no' => ['required', 'string'], 'round_uid' => ['required', Rule::exists('admission_rounds', 'uid')]]);
        $app = Application::where('course_id', $formula->course_id)->where('admission_cycle_id', $formula->admission_cycle_id)
            ->where('portal_application_no', $d['application_no'])->first() ?? $this->fail('application_no', 'No application with that number in this course and cycle.');
        $round = AdmissionRound::where('uid', $d['round_uid'])->where('admission_cycle_id', $formula->admission_cycle_id)->first() ?? $this->fail('round_uid', 'The round belongs to another cycle.');

        return response()->json(['data' => $this->merit->preview($formula, $app, $round) + ['application' => ['uid' => $app->uid, 'name' => $app->applicant->full_name]]]);
    }

    public static function asInput(MeritFormula $f): array
    {
        return [
            'name' => $f->name, 'source_reference' => $f->source_reference, 'rounding' => $f->rounding, 'inclusion' => $f->inclusion, 'tie_breaks' => $f->tie_breaks,
            'components' => $f->components->map(fn ($c) => $c->only(['key', 'label', 'source', 'source_ref', 'method', 'params', 'when_missing', 'minimum'])
                + ['weight' => MeritEngine::dec($c->weight), 'cap' => MeritEngine::dec($c->cap)])->all(),
        ];
    }

    /** @return array{0: Course, 1: AdmissionCycle} */
    private function courseCycle(Request $request): array
    {
        $d = $request->validate(['course_uid' => ['required', Rule::exists('courses', 'uid')], 'cycle_uid' => ['required', Rule::exists('admission_cycles', 'uid')]]);

        return [Course::where('uid', $d['course_uid'])->firstOrFail(), AdmissionCycle::where('uid', $d['cycle_uid'])->firstOrFail()];
    }
}

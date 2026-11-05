<?php

namespace App\Modules\Cycles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdmissionCycleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'cycles.view');

        $query = AdmissionCycle::query()->with('academicYear')
            ->withCount(['offerings', 'rounds'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->orderByDesc('id');
        $this->access()->applyScope($query, $this->actor(), 'cycles.view', ['cycle' => 'admission_cycles.id']);

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function show(AdmissionCycle $admissionCycle): JsonResponse
    {
        $this->access()->authorizeSee($this->actor(), 'cycles.view', Scope::cycle($admissionCycle->id));

        return response()->json(['data' => $admissionCycle->load('academicYear')->loadCount(['offerings', 'rounds'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'cycles.manage');
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('admission_cycles')],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $cycle = DB::transaction(function () use ($data) {
            $cycle = AdmissionCycle::create($data + ['status' => AdmissionCycle::DRAFT]);
            $this->audit()->created('cycle.created', $cycle, Scope::cycle($cycle->id));

            return $cycle;
        });

        return response()->json(['data' => $cycle->fresh('academicYear')], 201);
    }

    public function update(Request $request, AdmissionCycle $admissionCycle): JsonResponse
    {
        $scope = Scope::cycle($admissionCycle->id);
        $this->access()->authorize($this->actor(), 'cycles.manage', $scope, 'cycles.view');
        $this->ensureNotClosed($admissionCycle);

        $data = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', Rule::exists('academic_years', 'id')],
            'code' => ['sometimes', 'string', 'max:32', 'alpha_dash', Rule::unique('admission_cycles')->ignore($admissionCycle->id)],
            'name' => ['sometimes', 'string', 'max:255'],
        ]);
        $inUse = $admissionCycle->offerings()->exists() || $admissionCycle->rounds()->exists();
        if (isset($data['code']) && $data['code'] !== $admissionCycle->code && $inUse) {
            $this->fail('code', 'The code cannot change once the cycle has offerings or rounds.');
        }
        if (isset($data['academic_year_id']) && (int) $data['academic_year_id'] !== $admissionCycle->academic_year_id && $inUse) {
            $this->fail('academic_year_id', 'The academic year cannot change once the cycle has offerings or rounds.');
        }

        DB::transaction(fn () => $this->audit()->update('cycle.updated', $admissionCycle, $data, $scope));

        return response()->json(['data' => $admissionCycle->fresh('academicYear')]);
    }

    public function open(AdmissionCycle $admissionCycle): JsonResponse
    {
        $scope = Scope::cycle($admissionCycle->id);
        $this->access()->authorize($this->actor(), 'cycles.manage', $scope, 'cycles.view');
        if ($admissionCycle->status !== AdmissionCycle::DRAFT) {
            $this->fail('status', 'Only a draft cycle can be opened.');
        }
        if (! $admissionCycle->offerings()->where('status', 'active')->exists()) {
            $this->fail('status', 'Add at least one active course offering before opening the cycle.');
        }

        DB::transaction(fn () => $this->audit()->update('cycle.opened', $admissionCycle, ['status' => AdmissionCycle::OPEN], $scope));

        return response()->json(['data' => $admissionCycle->fresh('academicYear')]);
    }

    public function close(Request $request, AdmissionCycle $admissionCycle): JsonResponse
    {
        $scope = Scope::cycle($admissionCycle->id);
        $this->access()->authorize($this->actor(), 'cycles.manage', $scope, 'cycles.view');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted']]);
        if ($admissionCycle->status !== AdmissionCycle::OPEN) {
            $this->fail('status', 'Only an open cycle can be closed.');
        }
        if ($admissionCycle->rounds()->where('status', '!=', AdmissionRound::CLOSED)->exists()) {
            $this->fail('status', 'Close every round of this cycle first.');
        }

        DB::transaction(fn () => $this->audit()->update('cycle.closed', $admissionCycle, ['status' => AdmissionCycle::CLOSED], $scope, $data['reason']));

        return response()->json(['data' => $admissionCycle->fresh('academicYear')]);
    }

    public static function ensureNotClosed(AdmissionCycle $cycle): void
    {
        if ($cycle->isClosed()) {
            abort(409, 'This cycle is closed and read-only.');
        }
    }
}

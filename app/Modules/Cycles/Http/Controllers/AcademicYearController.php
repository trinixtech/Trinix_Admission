<?php

namespace App\Modules\Cycles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcademicYearController extends Controller
{
    public function index(): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'cycles.view');

        return response()->json(['data' => AcademicYear::orderByDesc('starts_on')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'cycles.manage');
        $data = $this->validated($request);

        $year = DB::transaction(function () use ($data) {
            $year = AcademicYear::create($data);
            $this->audit()->created('academic_year.created', $year);

            return $year;
        });

        return response()->json(['data' => $year], 201);
    }

    public function update(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'cycles.manage');
        $data = $this->validated($request, $academicYear);
        if (isset($data['code']) && $data['code'] !== $academicYear->code && $academicYear->cycles()->exists()) {
            $this->fail('code', 'The code cannot change once cycles use this academic year.');
        }

        DB::transaction(fn () => $this->audit()->update('academic_year.updated', $academicYear, $data));

        return response()->json(['data' => $academicYear->fresh()]);
    }

    private function validated(Request $request, ?AcademicYear $year = null): array
    {
        $required = $year ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:16', 'regex:/^\d{4}-\d{2}$/', Rule::unique('academic_years')->ignore($year?->id)],
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date', 'after:starts_on'],
        ], ['code.regex' => 'Use the form 2027-28.']);
    }
}

<?php

namespace App\Modules\Selection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Organisation\Models\Course;
use App\Modules\Selection\Services\ScheduleImport;
use App\Support\Rules\UploadedTable;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Interview dates, panels, slots and members from the Excel template: download, upload for a preview, confirm. */
class ScheduleImportController extends Controller
{
    public function __construct(private ScheduleImport $import) {}

    public function template(Request $request): Response
    {
        $d = $request->validate(['course_uid' => ['required', Rule::exists('courses', 'uid')], 'cycle_uid' => ['required', Rule::exists('admission_cycles', 'uid')]]);
        $course = Course::where('uid', $d['course_uid'])->firstOrFail();
        $cycle = AdmissionCycle::where('uid', $d['cycle_uid'])->firstOrFail();
        $this->access()->authorize($this->actor(), 'panels.manage', new Scope(null, $course->id, $cycle->id));

        return response($this->import->template($course->id, $cycle->id), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"interview-schedule-{$course->code}.xlsx\"", 'Cache-Control' => 'private, no-store']);
    }

    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate(['course_uid' => ['required', Rule::exists('courses', 'uid')], 'round_uid' => ['required', Rule::exists('admission_rounds', 'uid')],
            'file' => ['required', 'file', 'max:5120', new UploadedTable]]);
        $course = Course::where('uid', $d['course_uid'])->firstOrFail();
        $round = AdmissionRound::where('uid', $d['round_uid'])->firstOrFail();
        $this->access()->authorize($this->actor(), 'panels.manage', new Scope(null, $course->id, $round->admission_cycle_id));
        if ($round->status === 'closed') {
            $this->fail('round_uid', 'This round is closed.');
        }

        return response()->json(['data' => $this->import->preview($request->file('file'), $course->id, $round, $this->actor())]);
    }

    public function confirm(string $token): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'panels.manage');
        $c = $this->import->confirm($token, $this->actor());

        return response()->json(['data' => $c, 'message' => "Saved: {$c['panels']} new panels, {$c['slots']} slots, {$c['members']} panel members."]);
    }
}

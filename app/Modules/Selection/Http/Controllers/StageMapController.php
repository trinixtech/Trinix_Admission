<?php

namespace App\Modules\Selection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Organisation\Models\Course;
use App\Modules\Selection\Models\SelectionStage;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The stage map of a course and cycle: the stages in order with their components and maximum marks, what may still
 * change, live counts for a round, and the venues and panels of each stage (so GD and PI at one place can be checked).
 */
class StageMapController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $d = $request->validate([
            'course_uid' => ['required', Rule::exists('courses', 'uid')],
            'cycle_uid' => ['required', Rule::exists('admission_cycles', 'uid')],
            'round_uid' => ['nullable', Rule::exists('admission_rounds', 'uid')],
        ]);
        $course = Course::where('uid', $d['course_uid'])->firstOrFail();
        $cycle = AdmissionCycle::where('uid', $d['cycle_uid'])->firstOrFail();
        $scope = new Scope(null, $course->id, $cycle->id);
        if (! $this->access()->canSee($this->actor(), 'stages.configure', $scope)) {
            $this->access()->authorizeSee($this->actor(), 'panels.manage', $scope);
        }
        $round = isset($d['round_uid']) ? AdmissionRound::where('uid', $d['round_uid'])->where('admission_cycle_id', $cycle->id)->firstOrFail() : null;
        $candidates = $round ? DB::table('application_round_participations')->join('applications', 'applications.id', '=', 'application_round_participations.application_id')
            ->where('application_round_participations.admission_round_id', $round->id)->where('applications.course_id', $course->id)->count() : null;

        $stages = SelectionStage::with(['criteria' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->where(['course_id' => $course->id, 'admission_cycle_id' => $cycle->id])->orderBy('sort_order')->orderBy('id')->get();
        $tz = config('app.display_timezone', 'Asia/Kolkata');

        $out = $stages->map(function (SelectionStage $s) use ($round) {
            $counts = null;
            $venues = [];
            if ($round) {
                $base = DB::table('interview_candidates')->where('selection_stage_id', $s->id)->where('admission_round_id', $round->id)->where('active', true);
                $counts = [
                    'scheduled' => (clone $base)->count(),
                    'present' => (clone $base)->whereIn('status', ['present', 'completed'])->count(),
                    'absent' => (clone $base)->where('status', 'absent')->count(),
                    'locked' => (clone $base)->whereNotNull('result_locked_at')->count(),
                ];
                $panels = DB::table('interview_panels')->leftJoin('locations', 'locations.id', '=', 'interview_panels.location_id')
                    ->where('interview_panels.selection_stage_id', $s->id)->where('interview_panels.admission_round_id', $round->id)->where('interview_panels.status', 'active')
                    ->orderBy('panel_date')->orderBy('interview_panels.name')
                    ->get(['interview_panels.id', 'interview_panels.uid', 'interview_panels.name', 'interview_panels.panel_date', 'interview_panels.mode', 'interview_panels.venue',
                        'interview_panels.location_id', 'locations.name as location', 'locations.city']);
                $booked = DB::table('interview_candidates')->join('interview_slots', 'interview_slots.id', '=', 'interview_candidates.interview_slot_id')
                    ->whereIn('interview_slots.interview_panel_id', $panels->pluck('id'))->where('interview_candidates.active', true)
                    ->selectRaw('interview_slots.interview_panel_id as p, count(*) as n')->groupBy('interview_slots.interview_panel_id')->pluck('n', 'p');
                $members = DB::table('interview_panel_members')->whereIn('interview_panel_id', $panels->pluck('id'))->whereNull('removed_at')
                    ->selectRaw('interview_panel_id as p, count(*) as n')->groupBy('interview_panel_id')->pluck('n', 'p');
                foreach ($panels->groupBy(fn ($p) => $p->mode === 'online' ? 'online' : 'loc-'.$p->location_id) as $key => $group) {
                    $f = $group->first();
                    $venues[] = [
                        'key' => $key, 'mode' => $f->mode, 'location' => $f->mode === 'online' ? 'Online' : $f->location, 'city' => $f->city,
                        'dates' => $group->pluck('panel_date')->map(fn ($x) => substr((string) $x, 0, 10))->unique()->values(),
                        'panels' => $group->map(fn ($p) => ['uid' => $p->uid, 'name' => $p->name, 'date' => substr((string) $p->panel_date, 0, 10), 'room' => $p->venue,
                            'candidates' => (int) ($booked[$p->id] ?? 0), 'members' => (int) ($members[$p->id] ?? 0)])->values(),
                    ];
                }
            }

            return [
                'uid' => $s->uid, 'code' => $s->code, 'name' => $s->name, 'status' => $s->status,
                'criteria' => $s->criteria->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'max_marks' => (float) $c->max_marks])->values(),
                'total_max' => (float) $s->criteria->sum('max_marks'), 'result_formula' => $s->result_formula ?? 'AVG',
                'usage' => StageController::usage($s), 'counts' => $counts, 'venues' => $venues,
            ];
        })->values();

        // Same place: every in-person venue of a later stage should be one the earlier in-person stage used.
        $warnings = [];
        $previous = null;
        foreach ($out as $s) {
            $places = collect($s['venues'])->where('mode', '!=', 'online')->pluck('location')->filter()->values();
            if ($s['status'] === 'active' && $previous && $places->isNotEmpty() && $previous['places']->isNotEmpty()) {
                $extra = $places->diff($previous['places']);
                if ($extra->isNotEmpty()) {
                    $warnings[] = "{$s['code']} has panels at ".$extra->join(', ')." where there was no {$previous['code']}. Candidates who had {$previous['code']} in person are kept at their {$previous['code']} venue.";
                }
            }
            if ($s['status'] === 'active' && $places->isNotEmpty()) {
                $previous = ['code' => $s['code'], 'places' => $places];
            }
        }

        return response()->json(['data' => ['stages' => $out, 'candidates' => $candidates, 'round' => $round?->only(['uid', 'name']), 'warnings' => $warnings,
            'can_configure' => $this->access()->can($this->actor(), 'stages.configure', $scope)]]);
    }
}

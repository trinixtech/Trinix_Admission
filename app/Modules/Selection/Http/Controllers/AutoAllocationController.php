<?php

namespace App\Modules\Selection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Models\SelectionStage;
use App\Modules\Selection\Services\AutoAllocation;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Automatic allocation of candidates and faculty to GD/PI panels: preview, then confirm (Stage A). */
class AutoAllocationController extends Controller
{
    public function __construct(private AutoAllocation $auto) {}

    public function previewCandidates(Request $request): JsonResponse
    {
        [$stage, $round, $panelIds] = $this->target($request);
        $useCentres = $request->boolean('use_centres', true);

        return response()->json(['data' => $this->auto->previewCandidates($stage, $round, $panelIds, $useCentres, $this->actor())]);
    }

    public function confirmCandidates(string $token): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'panels.manage');
        $r = $this->auto->confirmCandidates($token, $this->actor());
        DB::transaction(fn () => $this->audit()->record('panels.auto_allocated', null, null, ['scheduled' => $r['scheduled'], 'failed' => count($r['failed'])]));

        return response()->json(['data' => $r, 'message' => "{$r['scheduled']} candidates scheduled and emailed.".($r['failed'] ? ' '.count($r['failed']).' could not be placed (see the list).' : '')]);
    }

    public function previewFaculty(Request $request): JsonResponse
    {
        [$stage, $round, $panelIds] = $this->target($request);
        $d = $request->validate([
            'members_per_panel' => ['required', 'integer', 'min:1', 'max:10'], 'name_chair' => ['sometimes', 'boolean'],
            'branch_uid' => ['nullable', Rule::exists('branches', 'uid')],
        ]);
        $campus = ! empty($d['branch_uid']) ? Branch::where('uid', $d['branch_uid'])->first() : null;

        return response()->json(['data' => $this->auto->previewFaculty($stage, $round, $panelIds, $d['members_per_panel'], (bool) ($d['name_chair'] ?? false), $campus, $this->actor())]);
    }

    public function confirmFaculty(string $token): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'panels.manage');
        $r = $this->auto->confirmFaculty($token, $this->actor());

        return response()->json(['data' => $r, 'message' => "{$r['added']} panel places filled.".($r['failed'] ? ' '.count($r['failed']).' could not be added (see the list).' : '')]);
    }

    /** @return array{0: SelectionStage, 1: AdmissionRound, 2: ?list<int>} */
    private function target(Request $request): array
    {
        $d = $request->validate([
            'stage_uid' => ['required', Rule::exists('selection_stages', 'uid')], 'round_uid' => ['required', Rule::exists('admission_rounds', 'uid')],
            'panel_uids' => ['nullable', 'array'], 'panel_uids.*' => ['string'],
        ]);
        $stage = SelectionStage::where('uid', $d['stage_uid'])->firstOrFail();
        $round = AdmissionRound::where('uid', $d['round_uid'])->firstOrFail();
        if ($round->admission_cycle_id !== $stage->admission_cycle_id) {
            $this->fail('round_uid', 'The round belongs to another cycle.');
        }
        $this->access()->authorize($this->actor(), 'panels.manage', new Scope(null, $stage->course_id, $stage->admission_cycle_id), 'panels.manage');
        $ids = ! empty($d['panel_uids']) ? InterviewPanel::whereIn('uid', $d['panel_uids'])->where('selection_stage_id', $stage->id)->pluck('id')->all() : null;

        return [$stage, $round, $ids];
    }
}

<?php

namespace App\Modules\Cycles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Organisation\Models\Course;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The admission workflow for one course in one round, step by step, with live counts: what is done, what is
 * left and where to do it. Read-only; counts only (no names), for anyone who can see the course's cycle.
 */
class WorkflowController extends Controller
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
        $this->access()->authorizeSee($this->actor(), 'cycles.view', new Scope(null, $course->id, $cycle->id));
        $round = isset($d['round_uid']) ? AdmissionRound::where('uid', $d['round_uid'])->where('admission_cycle_id', $cycle->id)->firstOrFail() : null;

        $steps = [];

        // 1. Setup: the course is offered at a campus in this cycle, and the cycle has a round.
        $offerings = DB::table('course_offerings')->join('branch_courses', 'branch_courses.id', '=', 'course_offerings.branch_course_id')
            ->where('course_offerings.admission_cycle_id', $cycle->id)->where('branch_courses.course_id', $course->id)->count();
        $rounds = DB::table('admission_rounds')->where('admission_cycle_id', $cycle->id)->count();
        $steps[] = $this->step('setup', 'Setup', $offerings > 0 && $rounds > 0 ? 'done' : 'todo',
            $offerings > 0 ? "Offered at $offerings ".($offerings === 1 ? 'campus' : 'campuses').", $rounds ".($rounds === 1 ? 'round' : 'rounds').'.'
                : 'Add the course to the cycle (offerings and seats), then create Round 1.',
            "/cycles/{$cycle->uid}", 'Open the cycle');

        // 2. Faculty: people who can mark (current assignments with evaluations.submit).
        $faculty = DB::table('user_role_assignments')->join('role_permissions', 'role_permissions.role_id', '=', 'user_role_assignments.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->join('users', 'users.id', '=', 'user_role_assignments.user_id')
            ->where('permissions.key', 'evaluations.submit')->whereNull('user_role_assignments.revoked_at')->where('users.status', 'active')
            ->distinct()->count('user_role_assignments.user_id');
        $steps[] = $this->step('faculty', 'Faculty', $faculty > 0 ? 'done' : 'todo',
            $faculty > 0 ? "$faculty active ".($faculty === 1 ? 'person' : 'people').' can mark interviews.' : 'Invite faculty or import them from Excel/CSV.',
            '/people/users', 'People');

        if (! $round) {
            $steps[] = $this->step('students', 'Students', 'waiting', 'Create a round first.', "/cycles/{$cycle->uid}", 'Open the cycle');

            return response()->json(['data' => ['round' => null, 'steps' => $steps]]);
        }

        // 3. Students: applications of this course imported into the round; batches waiting for approval.
        $candidates = DB::table('application_round_participations')->join('applications', 'applications.id', '=', 'application_round_participations.application_id')
            ->where('application_round_participations.admission_round_id', $round->id)->where('applications.course_id', $course->id);
        $total = (clone $candidates)->count();
        $waiting = ImportBatch::where('admission_round_id', $round->id)->where(fn ($q) => $q->where('course_id', $course->id)->orWhereNull('course_id'))
            ->whereNotIn('status', [...ImportBatch::FINAL, ImportBatch::FAILED, ImportBatch::PARTIAL])->count();
        $steps[] = $this->step('students', 'Students', $total > 0 ? ($waiting ? 'attention' : 'done') : ($waiting ? 'attention' : 'todo'),
            ($total > 0 ? "$total ".($total === 1 ? 'applicant' : 'applicants').' in this round.' : 'Import the shortlisted applicants from the portal export.')
                .($waiting ? " $waiting ".($waiting === 1 ? 'import is' : 'imports are').' not finished (check, approve or cancel).' : ''),
            '/imports', 'Student imports', ['candidates' => $total]);

        // 4. Applicants' files (optional).
        $files = DB::table('application_documents')->whereIn('application_id', (clone $candidates)->select('applications.id'))->count();
        $steps[] = $this->step('files', 'Applicants\' files', $files > 0 ? 'done' : 'optional',
            $files > 0 ? "$files ".($files === 1 ? 'file' : 'files').' imported.' : 'Optional: set up document types, then import the files (ZIP or API).',
            '/documents/types', 'Document types');

        // 5. Stages and criteria.
        $stages = DB::table('selection_stages')->where('course_id', $course->id)->where('admission_cycle_id', $cycle->id)->where('status', 'active')
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'uid', 'code', 'name']);
        $criteria = DB::table('evaluation_criteria')->whereIn('selection_stage_id', $stages->pluck('id'))->where('status', 'active')
            ->selectRaw('selection_stage_id, count(*) as n')->groupBy('selection_stage_id')->pluck('n', 'selection_stage_id');
        $without = $stages->filter(fn ($s) => ! ($criteria[$s->id] ?? 0))->pluck('code')->all();
        $steps[] = $this->step('stages', 'Stages and criteria', $stages->isEmpty() ? 'todo' : ($without ? 'attention' : 'done'),
            $stages->isEmpty() ? 'Add the stages (e.g. GD, PI) and their marking criteria.'
                : $stages->pluck('code')->join(', ').($without ? '. No criteria yet: '.implode(', ', $without).'. Add criteria, or switch off stages you do not use.' : ', each with criteria.'),
            '/selection/stages', 'Stages and criteria');

        // 6–9. Per stage: schedule, emails, attendance and marks, lock.
        $perStage = [];
        foreach ($stages as $s) {
            $base = DB::table('interview_candidates')->where('selection_stage_id', $s->id)->where('admission_round_id', $round->id)->where('active', true)
                ->whereIn('application_id', (clone $candidates)->select('applications.id'));
            $perStage[] = [
                'uid' => $s->uid, 'code' => $s->code, 'name' => $s->name,
                'panels' => DB::table('interview_panels')->where('selection_stage_id', $s->id)->where('admission_round_id', $round->id)->where('status', 'active')->count(),
                'scheduled' => (clone $base)->count(),
                'not_emailed' => (clone $base)->where('status', 'scheduled')->whereNull('notified_at')->count(),
                'awaiting_marks' => (clone $base)->where('status', 'scheduled')->count(),
                'absent' => (clone $base)->where('status', 'absent')->count(),
                'locked' => (clone $base)->whereNotNull('result_locked_at')->count(),
            ];
        }
        $sum = fn (string $k) => array_sum(array_column($perStage, $k));
        $first = $perStage[0] ?? null;

        $steps[] = $this->step('schedule', 'Interview schedule', match (true) {
            ! $perStage || $sum('panels') === 0 => 'todo',
            $first && $first['scheduled'] < $total => 'attention',
            default => 'done',
        }, ! $perStage ? 'Add stages first.' : collect($perStage)->map(fn ($p) => "{$p['code']}: {$p['panels']} ".($p['panels'] === 1 ? 'panel' : 'panels').", {$p['scheduled']} of $total scheduled")->join('; ').'.',
            '/panels', 'Panels, slots and schedule', ['stages' => $perStage, 'candidates' => $total]);

        $notEmailed = $sum('not_emailed');
        $steps[] = $this->step('emails', 'Schedule emails', $sum('scheduled') === 0 ? 'waiting' : ($notEmailed ? 'attention' : 'done'),
            $sum('scheduled') === 0 ? 'Schedule candidates first. Booking never emails.'
                : ($notEmailed ? "$notEmailed booked ".($notEmailed === 1 ? 'candidate has' : 'candidates have').' not been emailed yet. Send when the schedule is final.' : 'Everyone booked has been emailed.'),
            '/panels', 'Send schedule emails');

        $marked = $sum('scheduled') - $sum('awaiting_marks');
        $steps[] = $this->step('marks', 'Attendance and marks', $sum('scheduled') === 0 ? 'waiting' : ($sum('awaiting_marks') ? 'todo' : 'done'),
            $sum('scheduled') === 0 ? 'Panels mark on their phones under My interviews.'
                : collect($perStage)->filter(fn ($p) => $p['scheduled'])->map(fn ($p) => "{$p['code']}: ".($p['scheduled'] - $p['awaiting_marks'])." of {$p['scheduled']} done".($p['absent'] ? " ({$p['absent']} absent)" : ''))->join('; ').'.',
            '/panels', 'Panel results', ['marked' => $marked]);

        $unlocked = $sum('scheduled') - $sum('locked');
        $steps[] = $this->step('lock', 'Lock results', $sum('scheduled') === 0 ? 'waiting' : ($unlocked ? 'todo' : 'done'),
            $sum('scheduled') === 0 ? 'The admin locks results when every panel member has submitted.'
                : ($unlocked ? "{$sum('locked')} of {$sum('scheduled')} results locked." : 'All results are locked.'),
            '/panels', 'Lock all complete results');

        // 10. Merit.
        $formula = DB::table('merit_formulas')->where('course_id', $course->id)->where('admission_cycle_id', $cycle->id)->orderByDesc('version')->first(['status', 'version']);
        $list = DB::table('merit_lists')->where('course_id', $course->id)->where('admission_round_id', $round->id)->orderByDesc('version')->first(['status', 'version', 'stale_since']);
        $steps[] = $this->step('merit', 'Merit list', match (true) {
            ! $formula || $formula->status !== 'approved' => 'todo',
            ! $list => 'todo',
            $list->stale_since !== null => 'attention',
            $list->status === 'published' => 'done',
            default => 'todo',
        }, match (true) {
            ! $formula => 'Enter the merit formula and have it approved.',
            $formula->status !== 'approved' => "Formula version {$formula->version} is {$formula->status}; it needs approval.",
            ! $list => 'Run the merit list for this round.',
            $list->stale_since !== null => "List version {$list->version} is out of date: marks changed. Run it again.",
            default => "List version {$list->version} is {$list->status}.".($list->status === 'published' ? '' : ' It needs approval and publishing by another person.'),
        }, ! $formula || $formula->status !== 'approved' ? '/merit/formula' : '/merit/lists', ! $formula || $formula->status !== 'approved' ? 'Merit formula' : 'Merit lists');

        return response()->json(['data' => ['round' => ['uid' => $round->uid, 'name' => $round->name], 'steps' => $steps]]);
    }

    /** @param  array<string, mixed>  $extra */
    private function step(string $key, string $title, string $status, string $detail, string $to, string $action, array $extra = []): array
    {
        return ['key' => $key, 'title' => $title, 'status' => $status, 'detail' => $detail, 'to' => $to, 'action' => $action, ...$extra];
    }
}

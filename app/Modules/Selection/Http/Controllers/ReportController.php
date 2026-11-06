<?php

namespace App\Modules\Selection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Services\ApplicationAccess;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Organisation\Models\Course;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\SelectionStage;
use App\Modules\Selection\Services\EvaluationService;
use App\Support\Scope;
use App\Support\Spreadsheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reports for one course in one round, on screen or as Excel (one sheet per table): interview progress,
 * marks and results, faculty workload, imports and applicants. Exports are recorded in the audit log.
 */
class ReportController extends Controller
{
    public const KINDS = ['progress', 'marks', 'workload', 'applicants'];

    /** Which permission (any of them) opens each report, within the course and cycle. */
    private const NEEDS = [
        'progress' => ['panels.manage', 'evaluations.view'],
        'marks' => ['evaluations.view'],
        'workload' => ['panels.manage', 'evaluations.view'],
        'applicants' => ['applicants.view', 'imports.view'],
    ];

    public function __construct(private EvaluationService $evaluations, private ApplicationAccess $applications) {}

    public function show(Request $request, string $kind): Response
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $d = $request->validate([
            'course_uid' => ['required', Rule::exists('courses', 'uid')],
            'cycle_uid' => ['required', Rule::exists('admission_cycles', 'uid')],
            'round_uid' => ['required', Rule::exists('admission_rounds', 'uid')],
            'stage_uid' => ['nullable', Rule::exists('selection_stages', 'uid')],
            'format' => ['nullable', Rule::in(['json', 'xlsx'])],
        ]);
        $course = Course::where('uid', $d['course_uid'])->firstOrFail();
        $cycle = AdmissionCycle::where('uid', $d['cycle_uid'])->firstOrFail();
        $round = AdmissionRound::where('uid', $d['round_uid'])->where('admission_cycle_id', $cycle->id)->firstOrFail();
        $scope = new Scope(null, $course->id, $cycle->id);
        $this->authorizeAny(self::NEEDS[$kind], $scope);

        $stages = SelectionStage::where(['course_id' => $course->id, 'admission_cycle_id' => $cycle->id, 'status' => 'active'])->orderBy('sort_order')->get();
        $stage = isset($d['stage_uid']) ? $stages->firstWhere('uid', $d['stage_uid']) ?? throw new NotFoundHttpException
            : ($stages->first(fn ($st) => InterviewCandidate::where(['selection_stage_id' => $st->id, 'admission_round_id' => $round->id])->exists()) ?? $stages->first());
        $tables = match ($kind) {
            'progress' => $this->progress($stages, $round),
            'marks' => $stage ? $this->marks($stage, $round) : [],
            'workload' => $this->workload($stages, $round),
            'applicants' => $this->applicants($course, $cycle, $round),
        };
        $title = ['progress' => 'Interview progress', 'marks' => 'Marks and results', 'workload' => 'Faculty workload', 'applicants' => 'Imports and applicants'][$kind];

        if (($d['format'] ?? 'json') === 'xlsx') {
            $this->audit()->record('report.exported', null, null, ['report' => $kind, 'course' => $course->code, 'round' => $round->name,
                'stage' => $kind === 'marks' ? $stage?->code : null, 'rows' => array_sum(array_map(fn ($t) => count($t['rows']), $tables))], scope: $scope);

            return Spreadsheet::sheets("$kind-{$course->code}-{$round->name}".($kind === 'marks' && $stage ? "-$stage->code" : '').'-'.now(config('app.display_timezone'))->format('Ymd'), $tables);
        }

        return response()->json(['data' => ['title' => $title, 'tables' => $tables, 'stage' => $stage?->only(['uid', 'code', 'name']),
            'stages' => $stages->map->only(['uid', 'code', 'name'])->values()]]);
    }

    /** Per stage and panel: scheduled, attendance, marking, locking, emails. */
    private function progress($stages, AdmissionRound $round): array
    {
        $rows = [];
        foreach ($stages as $s) {
            $panels = DB::table('interview_panels')->leftJoin('locations', 'locations.id', '=', 'interview_panels.location_id')
                ->where('selection_stage_id', $s->id)->where('admission_round_id', $round->id)->where('interview_panels.status', 'active')
                ->orderBy('panel_date')->orderBy('interview_panels.name')->get(['interview_panels.id', 'interview_panels.name', 'panel_date', 'mode', 'venue', 'locations.name as location']);
            foreach ($panels as $p) {
                $c = DB::table('interview_candidates')->join('interview_slots', 'interview_slots.id', '=', 'interview_candidates.interview_slot_id')
                    ->where('interview_slots.interview_panel_id', $p->id)->where('interview_candidates.active', true)
                    ->selectRaw("count(*) as total, sum(case when interview_candidates.status = 'scheduled' then 1 else 0 end) as waiting,
                        sum(case when interview_candidates.status in ('present','completed') then 1 else 0 end) as present,
                        sum(case when interview_candidates.status = 'absent' then 1 else 0 end) as absent,
                        sum(case when interview_candidates.status = 'completed' then 1 else 0 end) as completed,
                        sum(case when interview_candidates.result_locked_at is not null then 1 else 0 end) as locked,
                        sum(case when interview_candidates.notified_at is null and interview_candidates.status = 'scheduled' then 1 else 0 end) as not_emailed")->first();
                $rows[] = [$s->code, substr((string) $p->panel_date, 0, 10), $p->name, $p->mode === 'online' ? 'Online' : trim(($p->location ?? '').($p->venue ? " · $p->venue" : '')),
                    (int) $c->total, (int) $c->waiting, (int) $c->present, (int) $c->absent, (int) $c->completed, (int) $c->locked, (int) $c->not_emailed];
            }
        }

        return [['title' => 'Interview progress', 'columns' => ['Stage', 'Date', 'Panel', 'Where', 'Scheduled', 'Attendance not marked', 'Present', 'Absent', 'Marks complete', 'Locked', 'Not emailed'], 'rows' => $rows]];
    }

    /** One row per candidate and panel member: marks per component, total, unusual marks, the stage result and lock. */
    private function marks(SelectionStage $stage, AdmissionRound $round): array
    {
        $criteria = $stage->criteria()->where('status', 'active')->orderBy('sort_order')->get();
        $candidates = InterviewCandidate::with('application.applicant:id,full_name', 'slot.panel:id,name,panel_date')
            ->where(['selection_stage_id' => $stage->id, 'admission_round_id' => $round->id, 'active' => true])->get()
            ->sortBy(fn ($c) => [$c->slot->panel->panel_date, $c->slot->panel->name, $c->application->portal_application_no])->values();
        $rows = [];
        $summary = [];
        foreach ($candidates as $c) {
            $r = $this->evaluations->result($c);
            $base = [$c->application->portal_application_no, $c->application->applicant->full_name, $c->slot->panel->name, substr((string) $c->slot->panel->panel_date, 0, 10), $c->status];
            foreach ($r['evaluators'] as $e) {
                $rows[] = [...$base, $e['evaluator']['name'], ...$criteria->map(fn ($cr) => isset($e['scores'][$cr->id]) ? (float) $e['scores'][$cr->id] : null)->all(),
                    (float) $e['total'], $e['outlier'] ? 'Unusual' : '', $e['remarks']];
            }
            $summary[] = [...$base, count($r['evaluators']).' of '.count($this->evaluations->evaluators($c)), $r['average'] === null ? null : (float) $r['average'], $r['locked']];
        }

        return [
            ['title' => "$stage->code results", 'columns' => ['Application number', 'Name', 'Panel', 'Date', 'Status', 'Members submitted', 'Stage result', 'Locked'], 'rows' => $summary],
            ['title' => "$stage->code marks by member", 'columns' => ['Application number', 'Name', 'Panel', 'Date', 'Status', 'Panel member',
                ...$criteria->map(fn ($cr) => $cr->name.' (/'.(float) $cr->max_marks.')')->all(), 'Total', 'Flag', 'Remarks'], 'rows' => $rows],
        ];
    }

    /** Per panel member: panels, candidates to mark, submitted, still to do, conflicts. */
    private function workload($stages, AdmissionRound $round): array
    {
        $panels = DB::table('interview_panels')->whereIn('selection_stage_id', $stages->pluck('id'))->where('admission_round_id', $round->id)->where('status', 'active')->pluck('id');
        $members = DB::table('interview_panel_members')->join('users', 'users.id', '=', 'interview_panel_members.user_id')
            ->whereIn('interview_panel_id', $panels)->whereNull('removed_at')
            ->get(['users.id', 'users.name', 'users.email', 'users.designation', 'interview_panel_members.interview_panel_id', 'interview_panel_members.role']);
        $rows = [];
        foreach ($members->groupBy('id') as $userId => $mine) {
            $ids = $mine->pluck('interview_panel_id');
            $cands = DB::table('interview_candidates')->join('interview_slots', 'interview_slots.id', '=', 'interview_candidates.interview_slot_id')
                ->whereIn('interview_slots.interview_panel_id', $ids)->where('interview_candidates.active', true)
                ->whereIn('interview_candidates.status', ['present', 'completed'])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('panel_conflicts')->whereColumn('panel_conflicts.application_id', 'interview_candidates.application_id')
                    ->whereColumn('panel_conflicts.interview_panel_id', 'interview_slots.interview_panel_id')->where('panel_conflicts.user_id', $userId))
                ->pluck('interview_candidates.id');
            $submitted = DB::table('interview_evaluations')->whereIn('interview_candidate_id', $cands)->where('evaluator_id', $userId)->where('status', 'submitted')->count();
            $conflicts = DB::table('panel_conflicts')->whereIn('interview_panel_id', $ids)->where('user_id', $userId)->count();
            $dates = DB::table('interview_panels')->whereIn('id', $ids)->orderBy('panel_date')->pluck('panel_date')->map(fn ($x) => substr((string) $x, 0, 10))->unique()->implode(', ');
            $f = $mine->first();
            $rows[] = [$f->name, $f->email, $f->designation, $ids->count(), $mine->where('role', 'chair')->count(), $dates, $cands->count(), $submitted, $cands->count() - $submitted, $conflicts];
        }
        usort($rows, fn ($a, $b) => [$b[8], $a[0]] <=> [$a[8], $b[0]]);

        return [['title' => 'Faculty workload', 'columns' => ['Panel member', 'Email', 'Designation', 'Panels', 'As chair', 'Dates', 'Candidates to mark', 'Submitted', 'Still to mark', 'Conflicts declared'], 'rows' => $rows]];
    }

    /** Applicants of the course imported into the round, by category, gender and first centre preference; imports in progress. */
    private function applicants(Course $course, AdmissionCycle $cycle, AdmissionRound $round): array
    {
        $apps = $this->applications->apply(Application::query(), $this->actor(), 'applicants.view')
            ->with('applicant:id,gender')->where('applications.course_id', $course->id)->where('applications.admission_cycle_id', $cycle->id)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('application_round_participations as p')->whereColumn('p.application_id', 'applications.id')->where('p.admission_round_id', $round->id))
            ->get(['applications.id', 'applications.applicant_id', 'applications.category', 'applications.pwbd', 'applications.interview_centres']);
        $count = fn ($key) => $apps->groupBy($key)->map->count()->sortKeys()->map(fn ($n, $k) => [$k === '' ? '(blank)' : $k, $n, $apps->count() ? round($n * 100 / $apps->count(), 1) : 0])->values()->all();
        $batches = ImportBatch::with('uploader:id,name')->where('admission_round_id', $round->id)->where(fn ($q) => $q->where('course_id', $course->id)->orWhereNull('course_id'))
            ->orderByDesc('id')->get()->map(fn ($b) => [$b->original_filename ?? 'Portal push', $b->status, $b->uploader?->name, $b->created_at->setTimezone(config('app.display_timezone'))->format('Y-m-d H:i'),
                $b->approved_at?->setTimezone(config('app.display_timezone'))->format('Y-m-d H:i')])->all();

        return [
            ['title' => 'Summary', 'columns' => ['Applicants in this round', 'PwBD'], 'rows' => [[$apps->count(), $apps->where('pwbd', true)->count()]]],
            ['title' => 'By category', 'columns' => ['Category', 'Applicants', '%'], 'rows' => $count(fn ($a) => (string) $a->category)],
            ['title' => 'By gender', 'columns' => ['Gender', 'Applicants', '%'], 'rows' => $count(fn ($a) => (string) ($a->applicant?->gender ?? ''))],
            ['title' => 'By first GD-PI centre', 'columns' => ['First centre preference', 'Applicants', '%'], 'rows' => $count(fn ($a) => (string) (array_values($a->interview_centres ?? [])[0] ?? ''))],
            ['title' => 'Imports', 'columns' => ['File', 'Status', 'Uploaded by', 'Uploaded', 'Approved'], 'rows' => $batches],
        ];
    }

    /** 403 without any of the permissions; 404 when they hold one, but not for this course and cycle. */
    private function authorizeAny(array $permissions, Scope $scope): void
    {
        foreach ($permissions as $p) {
            if ($this->access()->can($this->actor(), $p, $scope)) {
                return;
            }
        }
        foreach ($permissions as $p) {
            if ($this->access()->canSee($this->actor(), $p)) {
                throw new NotFoundHttpException;
            }
        }
        throw new AuthorizationException('You cannot open this report.');
    }
}

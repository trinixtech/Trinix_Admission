<?php

namespace App\Modules\Merit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Services\ApplicationAccess;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Merit\Models\MeritList;
use App\Modules\Merit\Models\MeritListEntry;
use App\Modules\Merit\Services\MeritService;
use App\Modules\Organisation\Models\Course;
use App\Support\Printable;
use App\Support\Scope;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Merit lists per course and round: run, check, resolve ties, approve, publish, compare, export. */
class MeritListController extends Controller
{
    public function __construct(private MeritService $merit, private ApplicationAccess $applications) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['course_uid' => ['required', Rule::exists('courses', 'uid')], 'round_uid' => ['required', Rule::exists('admission_rounds', 'uid')]]);
        $course = Course::where('uid', $d['course_uid'])->firstOrFail();
        $round = AdmissionRound::where('uid', $d['round_uid'])->firstOrFail();
        $this->access()->authorizeSee($this->actor(), 'merit.view', new Scope(null, $course->id, $round->admission_cycle_id));

        return response()->json(['data' => MeritList::with('formula:id,uid,version,name', 'runner:id,uid,name', 'approver:id,uid,name', 'publisher:id,uid,name')
            ->where(['course_id' => $course->id, 'admission_round_id' => $round->id])->orderByDesc('version')->get()]);
    }

    public function run(Request $request): JsonResponse
    {
        $d = $request->validate(['course_uid' => ['required', Rule::exists('courses', 'uid')], 'round_uid' => ['required', Rule::exists('admission_rounds', 'uid')]]);
        $course = Course::where('uid', $d['course_uid'])->firstOrFail();
        $round = AdmissionRound::where('uid', $d['round_uid'])->firstOrFail();
        $this->access()->authorize($this->actor(), 'merit.manage', new Scope(null, $course->id, $round->admission_cycle_id), 'merit.view');
        $list = $this->merit->run($course->id, $round, $this->actor());

        return response()->json(['data' => $this->present($list), 'message' => 'List made. Check it; someone else approves and publishes it.'], 201);
    }

    public function show(MeritList $list): JsonResponse
    {
        $this->see($list);

        return response()->json(['data' => $this->present($list)]);
    }

    /** Entries in rank order. Filters: view = ranked | left_out | ties, category, q (number or name). */
    public function entries(Request $request, MeritList $list): JsonResponse
    {
        $this->see($list);
        $q = MeritListEntry::query()->join('applications as a', 'a.id', '=', 'merit_list_entries.application_id')->join('applicants as p', 'p.id', '=', 'a.applicant_id')
            ->select('merit_list_entries.*', 'a.uid as application_uid', 'a.portal_application_no', 'a.category', 'a.pwbd', 'p.full_name')
            ->where('merit_list_id', $list->id)
            ->when($request->query('view', 'ranked') === 'ranked', fn ($w) => $w->where('ranked', true))
            ->when($request->query('view') === 'left_out', fn ($w) => $w->where('ranked', false))
            ->when($request->query('view') === 'ties', fn ($w) => $w->where('tie_shared', true))
            ->when($request->query('category'), fn ($w, $c) => $c === 'PwBD' ? $w->where('a.pwbd', true) : $w->where('a.category', $c))
            ->when(trim((string) $request->query('q')), fn ($w, $s) => $w->where(fn ($x) => $x->where('a.portal_application_no', $s)->orWhere('p.full_name', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $s).'%')))
            ->orderByRaw('merit_list_entries.overall_rank is null')->orderBy('merit_list_entries.overall_rank')->orderBy('a.portal_application_no');
        $sort = $request->query('category') && $request->query('category') !== 'PwBD' ? 'category_rank' : ($request->query('category') === 'PwBD' ? 'pwbd_rank' : null);
        if ($sort) {
            $q->reorder()->orderBy("merit_list_entries.$sort")->orderBy('a.portal_application_no');
        }

        return response()->json($q->paginate($this->perPage($request))->through(fn ($e) => $this->entry($e)));
    }

    /** One candidate: raw values, points per component, total, ranks, tie-break, formula version. */
    public function breakdown(MeritList $list, Application $application): JsonResponse
    {
        $this->see($list);
        $e = MeritListEntry::with('scores.component', 'manualBy:id,uid,name')->where(['merit_list_id' => $list->id, 'application_id' => $application->id])->firstOrFail();
        $e->setAttribute('application_uid', $application->uid)->setAttribute('portal_application_no', $application->portal_application_no)
            ->setAttribute('full_name', $application->applicant->full_name)->setAttribute('category', $application->category)->setAttribute('pwbd', $application->pwbd);

        return response()->json(['data' => $this->entry($e) + [
            'formula' => $list->formula->only(['uid', 'version', 'name', 'rounding', 'tie_breaks']),
            'components' => $e->scores->sortBy(fn ($s) => $s->component->sort_order)->values()->map(fn ($s) => [
                'key' => $s->component->key, 'label' => $s->component->label, 'source' => $s->component->source, 'source_ref' => $s->component->source_ref,
                'method' => $s->component->method, 'weight' => $s->component->weight,
                'raw' => $e->inputs['values'][$s->component->key]['value'] ?? null, 'note' => $e->inputs['values'][$s->component->key]['note'] ?? null,
                'points' => $s->points,
            ]),
            'tie_values' => $e->inputs['tie'] ?? [],
            'manual_by' => $e->manualBy?->only(['uid', 'name']),
        ]]);
    }

    public function resolveTie(Request $request, MeritList $list): JsonResponse
    {
        $this->authorizeOn($list, 'merit.manage');
        $d = $request->validate(['application_uids' => ['required', 'array', 'min:2', 'max:50'], 'application_uids.*' => ['string'], 'reason' => ['required', 'string', 'max:1000']]);
        $ids = collect($d['application_uids'])->map(fn ($u) => Application::where('uid', $u)->value('id') ?? 0)->all();
        $this->merit->resolveTie($list, $ids, $d['reason'], $this->actor());

        return response()->json(['data' => $this->present($list->fresh()), 'message' => 'Order recorded with your reason.']);
    }

    public function approve(MeritList $list): JsonResponse
    {
        $this->authorizeOn($list, 'merit.approve');
        $this->merit->approve($list, $this->actor());

        return response()->json(['data' => $this->present($list->fresh()), 'message' => 'Approved.']);
    }

    public function publish(MeritList $list): JsonResponse
    {
        $this->authorizeOn($list, 'merit.approve');
        $this->merit->publish($list, $this->actor());

        return response()->json(['data' => $this->present($list->fresh()), 'message' => 'Published. Seat allocation (Phase 6) uses this list.']);
    }

    public function check(MeritList $list): JsonResponse
    {
        $this->see($list);
        $changed = $this->merit->check($list);
        $same = $this->merit->reproduce($list)['same'];

        return response()->json(['data' => $this->present($list->fresh()) + ['reproducible' => $same],
            'message' => ($changed ? 'Data has changed since this list was run: run a new version.' : 'The data is unchanged since this list was run.')
                .($same ? ' Re-scoring the stored inputs gives the same result.' : ' Re-scoring the stored inputs does NOT give the same result.')]);
    }

    public function compare(Request $request, MeritList $list): JsonResponse
    {
        $this->see($list);
        $other = MeritList::where('uid', $request->query('with'))->where(['course_id' => $list->course_id, 'admission_round_id' => $list->admission_round_id])->firstOrFail();

        return response()->json(['data' => $this->merit->compare($list, $other)]);
    }

    /** Excel with the full breakdown, or a notice-board PDF (number, name, category, total, ranks; no contact details). */
    public function export(Request $request, MeritList $list): Response
    {
        $this->authorizeOn($list, 'merit.manage', 'merit.view');
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'xlsx';
        $list->load('formula.components', 'course', 'round');
        $entries = MeritListEntry::with('application.applicant', 'scores')->where('merit_list_id', $list->id)
            ->orderByRaw('overall_rank is null')->orderBy('overall_rank')->get();
        $this->audit()->record('merit_list.exported', $list, null, ['format' => $format, 'rows' => $entries->count()], scope: MeritService::scope($list));
        $name = "merit-{$list->course->code}-{$list->round->name}-v{$list->version}";

        if ($format === 'pdf') {
            return Printable::response(view('merit.notice', ['list' => $list, 'entries' => $entries->where('ranked', true)])->render(), $name);
        }

        $components = $list->formula->components;
        $rows = $entries->map(function ($e) use ($components) {
            $scores = $e->scores->keyBy('merit_component_id');

            return [
                $e->overall_rank, $e->category_rank, $e->pwbd_rank, $e->application->portal_application_no, $e->application->applicant->full_name,
                $e->application->category, $e->application->pwbd ? 'Yes' : 'No', $e->total === null ? null : (float) $e->total,
                ...$components->flatMap(fn ($c) => [$e->inputs['values'][$c->key]['value'] ?? null, isset($scores[$c->id]) && $scores[$c->id]->points !== null ? (float) $scores[$c->id]->points : null])->all(),
                $e->ranked ? 'Yes' : 'No', $e->manual ? "Committee: {$e->manual_reason}" : $e->reason, $e->decided_by,
            ];
        });

        return Spreadsheet::download($name, ['Rank', 'Category rank', 'PwBD rank', 'Application number', 'Name', 'Category', 'PwBD', 'Total',
            ...$components->flatMap(fn ($c) => ["{$c->label} (value)", "{$c->label} (points)"])->all(), 'Ranked', 'Reason', 'Decided by'], $rows);
    }

    /** The application's place in published merit lists (Merit tab). */
    public function forApplication(Application $application): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'merit.view');
        if (! $this->applications->allows($this->actor(), 'merit.view', $application)) {
            throw new NotFoundHttpException;
        }
        $rows = MeritListEntry::with('list.round:id,uid,name', 'list.formula:id,version')->where('application_id', $application->id)
            ->whereHas('list', fn ($q) => $q->whereIn('status', ['published', 'superseded'])->whereNotNull('published_at'))->get()
            ->sortByDesc('list.published_at')->values()
            ->map(fn ($e) => ['list_uid' => $e->list->uid, 'round' => $e->list->round->name, 'version' => $e->list->version, 'status' => $e->list->status,
                'formula_version' => $e->list->formula->version, 'ranked' => $e->ranked, 'reason' => $e->reason, 'total' => $e->total,
                'overall_rank' => $e->overall_rank, 'category_rank' => $e->category_rank, 'pwbd_rank' => $e->pwbd_rank]);

        return response()->json(['data' => $rows]);
    }

    private function entry(MeritListEntry $e): array
    {
        return [
            'application_uid' => $e->application_uid, 'portal_application_no' => $e->portal_application_no, 'name' => $e->full_name,
            'category' => $e->category, 'pwbd' => (bool) $e->pwbd, 'ranked' => $e->ranked, 'reason' => $e->manual ? "Committee: {$e->manual_reason}" : $e->reason,
            'total' => $e->total, 'overall_rank' => $e->overall_rank, 'category_rank' => $e->category_rank, 'pwbd_rank' => $e->pwbd_rank,
            'tie_shared' => $e->tie_shared, 'decided_by' => $e->decided_by, 'manual' => $e->manual,
        ];
    }

    private function present(MeritList $list): array
    {
        $list->loadMissing('formula:id,uid,version,name', 'runner:id,uid,name', 'approver:id,uid,name', 'publisher:id,uid,name', 'round:id,uid,name,status');
        $scope = MeritService::scope($list);

        return $list->only(['uid', 'version', 'status', 'counts', 'inputs_hash', 'run_at', 'approved_at', 'published_at', 'stale_since']) + [
            'formula' => $list->formula, 'round' => $list->round, 'runner' => $list->runner, 'approver' => $list->approver, 'publisher' => $list->publisher,
            'can' => [
                'manage' => $this->access()->can($this->actor(), 'merit.manage', $scope),
                'approve' => $this->access()->can($this->actor(), 'merit.approve', $scope) && $list->run_by !== $this->actor()->id,
            ],
        ];
    }

    private function see(MeritList $list): void
    {
        $this->access()->authorizeSee($this->actor(), 'merit.view', MeritService::scope($list));
    }

    private function authorizeOn(MeritList $list, string $permission, ?string $see = 'merit.view'): void
    {
        $this->access()->authorize($this->actor(), $permission, MeritService::scope($list), $see);
    }
}

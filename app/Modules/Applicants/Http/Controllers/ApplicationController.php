<?php

namespace App\Modules\Applicants\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Models\ApplicationCorrection;
use App\Modules\Applicants\Models\ApplicationEntranceDetail;
use App\Modules\Applicants\Services\ApplicationAccess;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Imports\Models\ImportRecord;
use App\Modules\Merit\Services\MeritService;
use App\Modules\Organisation\Models\Course;
use App\Modules\Selection\Services\PanelAccess;
use App\Support\Spreadsheet;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApplicationController extends Controller
{
    /** Fields staff may correct by hand, with their validation rules. */
    private const CORRECTABLE = [
        'full_name' => ['applicant', ['required', 'string', 'max:255']],
        'date_of_birth' => ['applicant', ['required', 'date', 'before:today']],
        'gender' => ['applicant', ['required', 'in:Male,Female,Transgender']],
        'email' => ['applicant', ['required', 'email']],
        'mobile' => ['applicant', ['required', 'regex:/^([6-9]\d{9}|\+\d{8,15})$/']],
        'alternate_mobile' => ['applicant', ['nullable', 'regex:/^([6-9]\d{9}|\+\d{8,15})$/']],
        'guardian_name' => ['applicant', ['nullable', 'string', 'max:255']],
        'nationality' => ['applicant', ['nullable', 'string', 'max:64']],
        'domicile_state' => ['applicant', ['nullable', 'string', 'max:64']],
        'address' => ['applicant', ['nullable', 'string', 'max:1000']],
        'city' => ['applicant', ['nullable', 'string', 'max:100']],
        'state' => ['applicant', ['nullable', 'string', 'max:100']],
        'pincode' => ['applicant', ['nullable', 'digits:6']],
        'category' => ['application', ['required']],
        'pwbd' => ['application', ['required', 'boolean']],
        'pwbd_type' => ['application', ['nullable', 'string', 'max:64']],
        'work_experience_months' => ['application', ['nullable', 'integer', 'min:0', 'max:600']],
    ];

    public function __construct(private ApplicationAccess $applications) {}

    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'applicants.view');

        // simplePaginate avoids a full count over a million rows; the UI shows next/previous.
        return response()->json($this->listQuery($request)->simplePaginate($this->perPage($request)));
    }

    /** The applications in the current filters as Excel (contact details included); recorded in the audit log. */
    public function export(Request $request): BinaryFileResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'applicants.view');
        $query = $this->listQuery($request)->addSelect('applications.id', 'applicants.email', 'applicants.mobile', 'applicants.gender', 'applicants.city',
            'applications.interview_centres', 'cycles.name as cycle_name')->join('admission_cycles as cycles', 'cycles.id', '=', 'applications.admission_cycle_id');
        $total = (clone $query)->count();
        if ($total > 50000) {
            $this->fail('q', "$total applications match. Narrow the filters to 50,000 or fewer.");
        }
        $this->audit()->record('applications.exported', null, null, ['rows' => $total, 'filters' => array_filter($request->only(['q', 'course_uid', 'cycle_uid', 'round_uid', 'category', 'status']))]);
        $rows = (function () use ($query) {
            foreach ($query->cursor() as $a) {
                yield [$a->portal_application_no, $a->full_name, $a->course_code, $a->cycle_name, $a->category, (bool) $a->pwbd, $a->gender, $a->email, $a->mobile, $a->city,
                    $a->exam, $a->entrance_application_no, $a->percentile === null ? null : (float) $a->percentile, implode(', ', array_values($a->interview_centres ?? [])), $a->status];
            }
        })();

        return Spreadsheet::download('applications-'.now(config('app.display_timezone'))->format('Ymd-His'), ['Application number', 'Name', 'Course', 'Cycle', 'Category', 'PwBD', 'Gender', 'Email',
            'Mobile', 'City', 'Exam', 'Exam application number', 'Percentile', 'GD/PI centre preferences', 'Status'], $rows);
    }

    private function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $q = trim((string) $request->query('q', ''));

        $query = Application::query()
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->join('courses', 'courses.id', '=', 'applications.course_id')
            ->leftJoin('application_entrance_details as e', 'e.application_id', '=', 'applications.id')
            ->select('applications.uid', 'applications.portal_application_no', 'applications.category', 'applications.status',
                'applications.pwbd', 'applicants.full_name', 'courses.code as course_code', 'e.entrance_application_no', 'e.exam', 'e.percentile')
            ->when($q !== '', fn ($w) => $w->whereIn('applications.id', $this->matchingIds($q)))
            ->when($request->query('course_uid'), fn ($w, $c) => $w->where('applications.course_id', Course::where('uid', $c)->value('id') ?? 0))
            ->when($request->query('cycle_uid'), fn ($w, $c) => $w->where('applications.admission_cycle_id', AdmissionCycle::where('uid', $c)->value('id') ?? 0))
            ->when($request->query('round_uid'), fn ($w, $r) => $w->whereExists(fn ($e) => $e->select(DB::raw(1))->from('application_round_participations as p')
                ->whereColumn('p.application_id', 'applications.id')->where('p.admission_round_id', AdmissionRound::where('uid', $r)->value('id') ?? 0)))
            ->when($request->query('category'), fn ($w, $c) => $w->where('applications.category', $c))
            ->when($request->query('status'), fn ($w, $s) => $w->where('applications.status', $s))
            ->orderBy('applications.id', 'desc');

        return $this->applications->apply($query, $this->actor(), 'applicants.view');
    }

    /**
     * Ids of applications matching a search, as a UNION of index lookups (portal number, entrance number,
     * mobile, email, name prefix). One OR across joined tables would scan every row.
     */
    private function matchingIds(string $q): Builder
    {
        $upper = strtoupper($q);
        $byApplicant = fn (string $column, string $op, string $value) => DB::table('applications')->select('applications.id')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->where("applicants.$column", $op, $value);

        if (str_contains($q, '@')) {
            return $byApplicant('email', '=', mb_strtolower($q));
        }
        // Anything with a digit is a number (portal, CAT/IPMAT, mobile); otherwise it is the start of a name.
        if (preg_match('/\d/', $q)) {
            return DB::table('applications')->select('id')->where('portal_application_no', $upper)
                ->union(DB::table('application_entrance_details')->select('application_id')->where('entrance_application_no', $upper))
                ->union($byApplicant('mobile', '=', $q));
        }

        return $byApplicant('full_name', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $q).'%');
    }

    public function show(Application $application): JsonResponse
    {
        $panelView = $this->ensure($application, 'applicants.view', allowPanel: true);
        if ($panelView) {
            $this->audit()->record('application.viewed_by_panel', $application, scope: $application->scope());
        }
        $application->load([
            'applicant', 'course:id,uid,code,name,level', 'cycle:id,uid,code,name', 'source:id,uid,code,name', 'entrance',
            'academics', 'preferences.branch:id,uid,code,name', 'participations.round:id,uid,name,number',
            'statusHistory.actor:id,uid,name', 'corrections.actor:id,uid,name',
        ]);

        // Cross-reference: the same CAT/IPMAT number on other applications the user can see (never merged).
        $related = [];
        if ($no = $application->entrance?->entrance_application_no) {
            $q = Application::query()->where('applications.id', '!=', $application->id)
                ->whereIn('applications.id', ApplicationEntranceDetail::where('entrance_application_no', $no)->select('application_id'))
                ->with('course:id,code');
            $related = $this->applications->apply($q, $this->actor(), 'applicants.view')->get()
                ->map(fn ($a) => ['uid' => $a->uid, 'portal_application_no' => $a->portal_application_no, 'course' => $a->course->code])->all();
        }

        $imports = ImportRecord::where('application_id', $application->id)->whereNotNull('written_at')
            ->with('batch:id,uid,original_filename,channel,admission_round_id', 'batch.round:id,name')
            ->orderBy('written_at')->get()
            ->map(fn ($r) => [
                'batch' => $r->batch->uid, 'file' => $r->batch->original_filename, 'channel' => $r->batch->channel,
                'round' => $r->batch->round->name, 'row' => $r->row_number, 'classification' => $r->classification,
                'diff' => $r->diff, 'written_at' => $r->written_at->toIso8601String(),
            ]);

        $this->audit()->record('application.viewed', $application, scope: $application->scope());

        return response()->json(['data' => [
            ...$application->toArray(),
            'related' => $related,
            'imports' => $imports,
            'can_edit' => $this->applications->allows($this->actor(), 'applicants.edit', $application),
            'correctable' => array_keys(self::CORRECTABLE),
        ]]);
    }

    public function correct(Request $request, Application $application): JsonResponse
    {
        $this->ensure($application, 'applicants.view');
        $this->access()->authorizeAnywhere($this->actor(), 'applicants.edit');
        if (! $this->applications->allows($this->actor(), 'applicants.edit', $application)) {
            abort(403, 'This application is outside your assigned scope.');
        }

        $field = $request->validate(['field' => ['required', Rule::in(array_keys(self::CORRECTABLE))]])['field'];
        [$target, $rules] = self::CORRECTABLE[$field];
        if ($field === 'category') { // D28 point 8: the category never changes after import (a later import goes to update review)
            $this->fail('field', 'The category cannot be changed by staff. A corrected value comes only from a new import, through update review.');
        }
        $data = $request->validate(['value' => $rules, 'reason' => ['required', 'string', 'max:1000']]);

        $model = $target === 'applicant' ? $application->applicant : $application;
        $value = $data['value'];
        if ($field === 'email') {
            $value = mb_strtolower($value);
        }
        $old = $model->getAttribute($field);
        $old = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old;

        DB::transaction(function () use ($application, $model, $field, $value, $old, $data) {
            $model->forceFill([$field => $value])->save();
            ApplicationCorrection::create([
                'application_id' => $application->id, 'field' => $field,
                'old_value' => is_bool($old) ? (int) $old : $old, 'new_value' => is_bool($value) ? (int) $value : $value,
                'reason' => $data['reason'], 'actor_id' => $this->actor()->id, 'occurred_at' => now(),
            ]);
            $this->audit()->record('application.corrected', $application, [$field => $old], [$field => $value], $data['reason'], $application->scope());
            MeritService::markStale($application, "$field corrected");
        });

        return $this->show($application->fresh());
    }

    /** @return bool true when access comes only from sitting on the candidate's interview panel (D24) */
    private function ensure(Application $application, string $permission, bool $allowPanel = false): bool
    {
        if ($this->access()->hasAnywhere($this->actor(), $permission) && $this->applications->allows($this->actor(), $permission, $application)) {
            return false;
        }
        if ($allowPanel && PanelAccess::canSeeApplication($this->actor(), $application)) {
            return true;
        }
        if ($allowPanel && PanelAccess::onAnyPanel($this->actor())) {
            throw new NotFoundHttpException;
        }
        $this->access()->authorizeAnywhere($this->actor(), $permission);

        throw new NotFoundHttpException;
    }
}

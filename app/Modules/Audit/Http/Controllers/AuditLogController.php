<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisation\Models\Branch;
use App\Support\Scope;
use App\Support\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'audit.view');
        $query = $this->filtered($request)->with('actor:id,uid,name')->orderByDesc('id');

        $page = $query->paginate($this->perPage($request), ['id', 'occurred_at', 'actor_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'branch_id', 'reason']);
        $page->setCollection($page->getCollection()->map(fn (AuditLog $l) => $this->row($l)));

        return response()->json($page);
    }

    /** People and record types that appear in the rows this viewer may see, for the filters. */
    public function options(): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'audit.view');
        $actorIds = $this->scoped(AuditLog::query())->whereNotNull('actor_id')->distinct()->pluck('actor_id');

        return response()->json(['data' => [
            'people' => User::whereIn('id', $actorIds)->orderBy('name')->get(['uid', 'name', 'email'])->map(fn ($u) => ['value' => $u->uid, 'label' => "$u->name ($u->email)"]),
            'record_types' => $this->scoped(AuditLog::query())->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]]);
    }

    /** The filtered rows as Excel (at most 50,000), with what changed; the export itself is recorded. */
    public function export(Request $request): BinaryFileResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'audit.view');
        $query = $this->filtered($request)->with('actor:id,uid,name,email');
        $total = (clone $query)->count();
        if ($total > 50000) {
            $this->fail('from', "$total rows match. Narrow the dates or filters to 50,000 rows or fewer.");
        }
        $this->audit()->record('audit.exported', null, null, ['rows' => $total, 'filters' => array_filter($request->only(['from', 'to', 'action', 'actor_uid', 'branch_uid', 'subject_type']))]);
        $tz = config('app.display_timezone', 'Asia/Kolkata');
        $rows = (function () use ($query, $tz) {
            foreach ($query->lazyByIdDesc(500, 'id') as $l) {
                $keys = array_unique([...array_keys($l->old_values ?? []), ...array_keys($l->new_values ?? [])]);
                $changes = implode('; ', array_map(fn ($k) => "$k: ".self::flat($l->old_values[$k] ?? null).' → '.self::flat($l->new_values[$k] ?? null), $keys));
                yield [$l->occurred_at->setTimezone($tz)->format('Y-m-d H:i:s'), $l->actor?->name ?? ($l->actor_type === 'system' ? 'System' : $l->actor_type), $l->actor?->email,
                    $l->action, $l->subject_type, $l->subject_id, $l->reason, mb_substr($changes, 0, 32000), $l->ip, $l->id];
            }
        })();

        return Spreadsheet::download('audit-log-'.now($tz)->format('Ymd-His'), ['Time (IST)', 'Person', 'Email', 'Action', 'Record', 'Record id', 'Reason', 'Changes', 'IP address', 'Audit id'], $rows);
    }

    public function show(int $id): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'audit.view');
        $log = $this->scoped(AuditLog::query())->with('actor:id,uid,name')->find($id) ?? throw new NotFoundHttpException;

        return response()->json(['data' => $this->row($log) + [
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'request_id' => $log->request_id,
        ]]);
    }

    private function filtered(Request $request): Builder
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return $this->scoped(AuditLog::query())
            ->when($request->query('from'), fn ($q, $d) => $q->where('occurred_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('occurred_at', '<', now()->parse($d)->addDay()))
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $a).'%'))
            ->when($request->query('actor_uid'), fn ($q, $u) => $q->where('actor_id', User::where('uid', $u)->value('id') ?? 0))
            ->when($request->query('branch_uid'), fn ($q, $b) => $q->where('branch_id', Branch::where('uid', $b)->value('id') ?? 0))
            ->when($request->query('subject_type'), fn ($q, $t) => $q->where('subject_type', $t));
    }

    private static function flat(mixed $v): string
    {
        return $v === null ? '—' : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Scoped viewers see only rows recorded inside their scope; global events stay with global viewers. */
    private function scoped(Builder $query): Builder
    {
        return $this->access()->applyScope($query, $this->actor(), 'audit.view', [
            'branch' => 'branch_id', 'course' => 'course_id', 'cycle' => 'admission_cycle_id',
        ], 'covers');
    }

    private function row(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'occurred_at' => $log->occurred_at->toIso8601String(),
            'actor' => $log->actor ? ['uid' => $log->actor->uid, 'name' => $log->actor->name] : null,
            'actor_type' => $log->actor_type,
            'action' => $log->action,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'branch_id' => $log->branch_id,
            'reason' => $log->reason,
        ];
    }
}

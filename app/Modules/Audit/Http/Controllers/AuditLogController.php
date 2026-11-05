<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisation\Models\Branch;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'audit.view');
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = $this->scoped(AuditLog::query())
            ->with('actor:id,uid,name')
            ->when($request->query('from'), fn ($q, $d) => $q->where('occurred_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('occurred_at', '<', now()->parse($d)->addDay()))
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $a).'%'))
            ->when($request->query('actor_uid'), fn ($q, $u) => $q->where('actor_id', User::where('uid', $u)->value('id') ?? 0))
            ->when($request->query('branch_uid'), fn ($q, $b) => $q->where('branch_id', Branch::where('uid', $b)->value('id') ?? 0))
            ->when($request->query('subject_type'), fn ($q, $t) => $q->where('subject_type', $t))
            ->orderByDesc('id');

        $page = $query->paginate($this->perPage($request), ['id', 'occurred_at', 'actor_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'branch_id', 'reason']);
        $page->setCollection($page->getCollection()->map(fn (AuditLog $l) => $this->row($l)));

        return response()->json($page);
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

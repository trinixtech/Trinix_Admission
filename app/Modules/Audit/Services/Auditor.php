<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * Writes audit rows. Call it inside the same database transaction as the change,
 * so a change and its audit row are saved together or not at all.
 */
class Auditor
{
    /** Attributes never written to the audit log. */
    public const SECRET_KEYS = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token',
    ];

    /** Bookkeeping columns that add noise to diffs. */
    private const IGNORED_KEYS = ['id', 'uid', 'created_at', 'updated_at'];

    public function record(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?Scope $scope = null,
        ?int $actorId = null,
        string $actorType = 'user',
    ): AuditLog {
        $request = request();
        $actorId ??= Auth::id();

        return AuditLog::create([
            'occurred_at' => now(),
            'actor_id' => $actorId,
            'actor_type' => $actorId === null && $actorType === 'user' ? 'system' : $actorType,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            ...($scope ?? Scope::global())->toArray(),
            'old_values' => $old === null ? null : $this->clean($old),
            'new_values' => $new === null ? null : $this->clean($new),
            'reason' => $reason,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'request_id' => $request?->attributes->get('request_id'),
        ]);
    }

    public function created(string $action, Model $model, ?Scope $scope = null, ?string $reason = null): AuditLog
    {
        return $this->record($action, $model, null, $model->getAttributes(), $reason, $scope);
    }

    /**
     * Apply changes to a model, save it, and audit only the fields that changed.
     * Returns null (and writes nothing) when nothing changed.
     */
    public function update(string $action, Model $model, array $changes, ?Scope $scope = null, ?string $reason = null): ?AuditLog
    {
        // Callers pass validated data only, so fields outside $fillable (status, lock fields) are allowed here.
        $model->forceFill($changes);
        $dirty = $model->getDirty();
        if ($dirty === []) {
            return null;
        }
        $old = Arr::only($model->getOriginal(), array_keys($dirty));
        $model->save();

        return $this->record($action, $model, $old, $dirty, $reason, $scope);
    }

    private function clean(array $values): array
    {
        $values = Arr::except($values, [...self::SECRET_KEYS, ...self::IGNORED_KEYS]);

        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $values);
    }
}

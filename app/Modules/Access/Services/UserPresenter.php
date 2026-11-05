<?php

namespace App\Modules\Access\Services;

use App\Modules\Access\Models\User;
use App\Modules\Access\Models\UserRoleAssignment;

class UserPresenter
{
    public static function summary(User $user): array
    {
        return [
            'uid' => $user->uid,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'designation' => $user->designation,
            'department' => $user->department,
            'status' => $user->status,
            'locked' => $user->isLocked(),
            'locked_until' => $user->locked_until?->toIso8601String(),
            'two_factor_enabled' => $user->hasConfirmedTwoFactor(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    public static function assignment(UserRoleAssignment $a): array
    {
        $a->loadMissing('role', 'branch:id,uid,name', 'course:id,uid,name,code', 'cycle:id,uid,name,code');

        return [
            'uid' => $a->uid,
            'role' => ['key' => $a->role->key, 'name' => $a->role->name],
            'branch' => $a->branch ? ['uid' => $a->branch->uid, 'name' => $a->branch->name] : null,
            'course' => $a->course ? ['uid' => $a->course->uid, 'name' => $a->course->name, 'code' => $a->course->code] : null,
            'cycle' => $a->cycle ? ['uid' => $a->cycle->uid, 'name' => $a->cycle->name, 'code' => $a->cycle->code] : null,
            'valid_from' => $a->valid_from?->toDateString(),
            'valid_to' => $a->valid_to?->toDateString(),
            'revoked_at' => $a->revoked_at?->toIso8601String(),
            'revoke_reason' => $a->revoke_reason,
            'active' => $a->revoked_at === null
                && $a->valid_from->lte(today())
                && ($a->valid_to === null || $a->valid_to->gte(today())),
        ];
    }
}

<?php

namespace App\Modules\Access\Models;

use App\Modules\Access\Notifications\InvitationNotification;
use App\Modules\Access\Notifications\ResetPasswordNotification;
use App\Support\HasUid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use HasFactory, HasUid, Notifiable, TwoFactorAuthenticatable;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    protected $attributes = [
        'status' => self::ACTIVE,
        'must_change_password' => false,
        'failed_logins' => 0,
    ];

    protected $fillable = ['name', 'email', 'phone', 'password', 'status', 'must_change_password', 'designation', 'department'];

    protected $hidden = ['id', 'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    /** Assignments that are not revoked and valid today. */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->active();
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function requiresTwoFactor(): bool
    {
        return $this->activeAssignments()
            ->whereHas('role', fn ($q) => $q->where('requires_two_factor', true))
            ->exists();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function sendInvitationNotification(string $token): void
    {
        $this->notify(new InvitationNotification($token));
    }
}

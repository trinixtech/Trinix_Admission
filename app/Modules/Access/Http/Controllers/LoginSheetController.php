<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\User;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Printable login sheet for panel and committee members: a temporary password per person, shown once to the
 * admin to print and hand out; each person must choose their own password at first sign-in. Accounts whose role
 * needs two-factor (administrators) are left out: they use invitation links.
 */
class LoginSheetController extends Controller
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function candidates(): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'users.manage');
        $users = $this->access()->applyUserScope(User::query(), $this->actor(), 'users.manage')
            ->where('status', User::ACTIVE)->whereKeyNot($this->actor()->id)
            ->whereHas('activeAssignments')
            ->with(['activeAssignments.role:id,name,requires_two_factor'])
            ->orderBy('name')->get()
            ->filter(fn (User $u) => ! $u->activeAssignments->contains(fn ($a) => $a->role?->requires_two_factor) && $this->access()->canManageUser($this->actor(), $u))
            ->map(fn (User $u) => [
                'uid' => $u->uid, 'name' => $u->name, 'email' => $u->email, 'designation' => $u->designation,
                'roles' => $u->activeAssignments->pluck('role.name')->unique()->values(),
                'signed_in' => $u->last_login_at !== null, 'must_change_password' => $u->must_change_password,
            ])->values();

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'users.manage');
        $data = $request->validate(['user_uids' => ['required', 'array', 'min:1', 'max:300'], 'user_uids.*' => ['string']]);
        $users = $this->access()->applyUserScope(User::query(), $this->actor(), 'users.manage')->whereIn('uid', $data['user_uids'])->get();
        if ($users->count() !== count(array_unique($data['user_uids']))) {
            $this->fail('user_uids', 'Some of these people cannot be found or are outside your scope.');
        }
        foreach ($users as $u) {
            if (! $u->isActive() || $u->id === $this->actor()->id || ! $this->access()->canManageUser($this->actor(), $u)) {
                $this->fail('user_uids', "$u->name cannot be given a login sheet (suspended, your own account, or outside your scope).");
            }
            if ($u->requiresTwoFactor()) {
                $this->fail('user_uids', "$u->name has an administrator role with two-factor sign-in: send them an invitation link instead.");
            }
        }

        $rows = DB::transaction(fn () => $users->sortBy('name')->values()->map(function (User $u) {
            $password = $this->temporaryPassword();
            $u->forceFill(['password' => $password, 'must_change_password' => true, 'failed_logins' => 0, 'locked_until' => null])->save();
            DB::table('user_invitation_tokens')->where('email', $u->email)->delete();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            $this->audit()->record('user.temporary_password', $u, null, ['must_change_password' => true], 'Printed login sheet', $this->scopeOf($u));

            return ['name' => $u->name, 'email' => $u->email, 'designation' => $u->designation,
                'roles' => $u->activeAssignments()->with('role:id,name')->get()->pluck('role.name')->unique()->values(), 'password' => $password];
        }));

        return response()->json(['data' => ['site' => config('app.url'), 'people' => $rows], 'message' => count($rows).' temporary passwords made. Print the sheet now: they are not shown again.']);
    }

    /** 12 characters without look-alikes (0/O, 1/l/I), in groups of four, e.g. "k7mq-3hxp-9tbw". */
    private function temporaryPassword(): string
    {
        $chars = '';
        for ($i = 0; $i < 12; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($chars, 4));
    }

    private function scopeOf(User $u): Scope
    {
        $a = $u->activeAssignments()->get();

        return $a->count() === 1 ? $a->first()->scope() : Scope::global();
    }
}

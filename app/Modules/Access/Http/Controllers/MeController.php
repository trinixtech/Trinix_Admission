<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\UserPresenter;
use App\Modules\Teams\Models\TeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeController extends Controller
{
    public function show(): JsonResponse
    {
        $user = $this->actor();
        $assignments = $user->activeAssignments()->get();
        $teams = TeamMember::with('team:id,uid,type,name')
            ->where('user_id', $user->id)->whereNull('removed_at')->get()
            ->map(fn ($m) => ['uid' => $m->team->uid, 'name' => $m->team->name, 'type' => $m->team->type, 'member_role' => $m->member_role]);

        return response()->json(['data' => UserPresenter::summary($user) + [
            'requires_two_factor' => $user->requiresTwoFactor(),
            'must_change_password' => $user->must_change_password,
            'permissions' => $this->access()->permissionsAnywhere($user),
            'assignments' => $assignments->map(fn ($a) => UserPresenter::assignment($a))->values(),
            'teams' => $teams,
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);
        DB::transaction(fn () => $this->audit()->update('user.profile_updated', $this->actor(), $data));

        return $this->show();
    }
}

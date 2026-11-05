<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\User;
use App\Modules\Access\Models\UserRoleAssignment;
use App\Modules\Access\Services\AssignmentService;
use App\Modules\Access\Services\Invitations;
use App\Modules\Access\Services\UserPresenter;
use App\Support\Scope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserController extends Controller
{
    public function __construct(private AssignmentService $assignments, private Invitations $invitations) {}

    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'users.view');

        $query = User::query()
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('role'), fn ($q, $r) => $q->whereHas('assignments', fn ($a) => $a->active()->whereHas('role', fn ($ro) => $ro->where('key', $r))))
            ->orderBy('name');
        $this->access()->applyUserScope($query, $this->actor(), 'users.view');

        $page = $query->paginate($this->perPage($request));
        $page->setCollection($page->getCollection()->map(fn (User $u) => UserPresenter::summary($u) + [
            'roles' => $u->activeAssignments()->with('role')->get()->pluck('role.name')->unique()->values(),
        ]));

        return response()->json($page);
    }

    public function show(User $user): JsonResponse
    {
        $this->ensureVisible($user, 'users.view');

        return response()->json(['data' => $this->present($user)]);
    }

    /** Invite a user. Anyone without global user management must give a first role at the same time. */
    public function store(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'users.manage');
        $global = $this->access()->can($this->actor(), 'users.manage', Scope::global());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')],
            'phone' => ['nullable', 'string', 'max:32'],
            'assignment' => [$global ? 'nullable' : 'required', 'array'],
            ...collect(AssignmentService::rules('assignment.'))
                ->map(fn ($rules, $key) => $key === 'assignment.role' ? ['required_with:assignment', ...array_slice($rules, 1)] : $rules)
                ->all(),
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => Str::random(64), // unusable until the user sets their own
                'status' => User::ACTIVE,
            ]);
            $scope = Scope::global();
            if (! empty($data['assignment'])) {
                $assignment = $this->assignments->assign($this->actor(), $user, $data['assignment']);
                $scope = $assignment->scope();
            }
            $this->audit()->record('user.invited', $user, null, ['name' => $user->name, 'email' => $user->email], scope: $scope);

            return $user;
        });

        $this->invitations->send($user);

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureManageable($user);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }

        DB::transaction(fn () => $this->audit()->update('user.updated', $user, $data, $this->auditScope($user)));

        return response()->json(['data' => $this->present($user)]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->ensureManageable($user);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        if (! $user->isActive()) {
            $this->fail('status', 'This user is already suspended.');
        }

        DB::transaction(function () use ($user, $data) {
            $this->audit()->update('user.suspended', $user, ['status' => User::SUSPENDED], $this->auditScope($user), $data['reason']);
            // End any database-stored sessions now; Redis sessions are refused on their next request.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
        $this->access()->forget($user);

        return response()->json(['data' => $this->present($user)]);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->ensureManageable($user);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        if ($user->isActive()) {
            $this->fail('status', 'This user is already active.');
        }

        DB::transaction(fn () => $this->audit()->update('user.reactivated', $user, ['status' => User::ACTIVE], $this->auditScope($user), $data['reason']));
        $this->access()->forget($user);

        return response()->json(['data' => $this->present($user)]);
    }

    public function unlock(User $user): JsonResponse
    {
        $this->ensureVisible($user, 'users.support');
        if (! $user->isLocked() && $user->failed_logins === 0) {
            $this->fail('locked', 'This account is not locked.');
        }

        DB::transaction(fn () => $this->audit()->update('user.unlocked', $user, ['failed_logins' => 0, 'locked_until' => null], $this->auditScope($user)));

        return response()->json(['data' => $this->present($user)]);
    }

    public function resendInvite(User $user): JsonResponse
    {
        $this->ensureVisible($user, 'users.support');
        if (! $user->isActive()) {
            $this->fail('status', 'Reactivate the user before sending an invitation.');
        }

        DB::transaction(fn () => $this->audit()->record('user.invitation_resent', $user, scope: $this->auditScope($user)));
        $this->invitations->send($user);

        return response()->json(['message' => 'Invitation sent.']);
    }

    public function assignments(User $user): JsonResponse
    {
        $this->ensureVisible($user, 'users.view');

        return response()->json(['data' => $user->assignments()->latest('id')->get()->map(fn ($a) => UserPresenter::assignment($a))]);
    }

    public function storeAssignment(Request $request, User $user): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'users.manage');
        $this->ensureVisible($user, 'users.view', allowUnassigned: true);
        $data = $request->validate(AssignmentService::rules());
        $assignment = $this->assignments->assign($this->actor(), $user, $data);

        return response()->json(['data' => UserPresenter::assignment($assignment)], 201);
    }

    public function revokeAssignment(Request $request, UserRoleAssignment $assignment): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'users.manage');
        $this->ensureVisible($assignment->user, 'users.view');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->assignments->revoke($this->actor(), $assignment, $data['reason']);

        return response()->json(['data' => UserPresenter::assignment($assignment->fresh())]);
    }

    private function ensureVisible(User $user, string $permission, bool $allowUnassigned = false): void
    {
        $this->access()->authorizeAnywhere($this->actor(), $permission);
        $visible = $this->access()->applyUserScope(User::query()->whereKey($user->id), $this->actor(), $permission)->exists();
        if (! $visible && ! ($allowUnassigned && ! $user->activeAssignments()->exists())) {
            throw new NotFoundHttpException;
        }
    }

    private function ensureManageable(User $user): void
    {
        $this->ensureVisible($user, 'users.view');
        if (! $this->access()->canManageUser($this->actor(), $user)) {
            throw new AuthorizationException('You cannot manage this user: they hold a role outside your scope, or it is your own account.');
        }
    }

    /** Audit rows about a user carry the scope of their single assignment, or none. */
    private function auditScope(User $user): Scope
    {
        $assignments = $user->activeAssignments()->get();

        return $assignments->count() === 1 ? $assignments->first()->scope() : Scope::global();
    }

    private function present(User $user): array
    {
        $user->refresh();

        return UserPresenter::summary($user) + [
            'can_manage' => $this->access()->canManageUser($this->actor(), $user),
            'assignments' => $user->assignments()->latest('id')->get()->map(fn ($a) => UserPresenter::assignment($a)),
        ];
    }
}

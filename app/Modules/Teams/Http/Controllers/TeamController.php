<?php

namespace App\Modules\Teams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\User;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\CourseOffering;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'teams.view');

        $query = Team::query()
            ->with(['branch:id,uid,name', 'cycle:id,uid,name,code', 'offering.branchCourse.course:id,name,code'])
            ->withCount('currentMembers')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->orderBy('name');
        $this->access()->applyScope($query, $this->actor(), 'teams.view', [
            'branch' => 'teams.branch_id', 'cycle' => 'teams.admission_cycle_id',
        ]);

        $page = $query->paginate($this->perPage($request));
        $page->setCollection($page->getCollection()->map(fn (Team $t) => $this->summary($t)));

        return response()->json($page);
    }

    public function show(Team $team): JsonResponse
    {
        $this->ensureVisible($team);

        return response()->json(['data' => $this->present($team)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'teams.manage');
        $data = $this->validated($request);
        $team = new Team($data);
        $this->access()->authorize($this->actor(), 'teams.manage', $team->scope());

        DB::transaction(function () use ($team) {
            $team->save();
            $this->audit()->created('team.created', $team, $team->scope());
        });

        return response()->json(['data' => $this->present($team)], 201);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $this->ensureVisible($team);
        $this->access()->authorize($this->actor(), 'teams.manage', $team->scope());
        $data = $this->validated($request, $team);
        $after = (clone $team)->fill($data);
        $after->unsetRelation('offering');
        $this->access()->authorize($this->actor(), 'teams.manage', $after->scope());

        DB::transaction(fn () => $this->audit()->update('team.updated', $team, $data, $after->scope()));

        return response()->json(['data' => $this->present($team)]);
    }

    public function members(Team $team): JsonResponse
    {
        $this->ensureVisible($team);

        return response()->json(['data' => $this->memberRows($team)]);
    }

    public function addMember(Request $request, Team $team): JsonResponse
    {
        $this->ensureVisible($team);
        $this->access()->authorize($this->actor(), 'teams.manage', $team->scope());
        $data = $request->validate([
            'user_uid' => ['required', 'string', Rule::exists('users', 'uid')],
            'member_role' => ['required', Rule::in(TeamMember::ROLES)],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        $user = User::where('uid', $data['user_uid'])->firstOrFail();
        if (! $this->access()->canSeeUser($this->actor(), $user)) {
            $this->fail('user_uid', 'You can only add users you can see.');
        }
        if (! $user->isActive()) {
            $this->fail('user_uid', 'This user is suspended.');
        }
        if ($team->currentMembers()->where('user_id', $user->id)->exists()) {
            $this->fail('user_uid', 'This user is already a member of this team.');
        }

        DB::transaction(function () use ($team, $user, $data) {
            $member = TeamMember::create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'member_role' => $data['member_role'],
                'valid_from' => $data['valid_from'] ?? ($team->valid_from->isFuture() ? $team->valid_from : today()),
                'valid_to' => $data['valid_to'] ?? $team->valid_to,
                'added_by' => $this->actor()->id,
            ]);
            $this->audit()->record('team.member_added', $team, null, [
                'user' => $user->uid, 'name' => $user->name, 'member_role' => $member->member_role,
            ], scope: $team->scope());
        });

        return response()->json(['data' => $this->memberRows($team)], 201);
    }

    public function removeMember(Request $request, TeamMember $teamMember): JsonResponse
    {
        $team = $teamMember->team;
        $this->ensureVisible($team);
        $this->access()->authorize($this->actor(), 'teams.manage', $team->scope());
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        if ($teamMember->removed_at !== null) {
            $this->fail('member', 'This member was already removed.');
        }

        DB::transaction(function () use ($teamMember, $team, $data) {
            $teamMember->forceFill(['removed_at' => now(), 'removed_by' => $this->actor()->id])->save();
            $this->audit()->record('team.member_removed', $team, [
                'user' => $teamMember->user->uid, 'name' => $teamMember->user->name, 'member_role' => $teamMember->member_role,
            ], null, $data['reason'] ?? null, $team->scope());
        });

        return response()->json(['data' => $this->memberRows($team)]);
    }

    private function validated(Request $request, ?Team $team = null): array
    {
        $required = $team ? 'sometimes' : 'required';
        $data = $request->validate([
            'type' => [$required, Rule::in(Team::TYPES)],
            'name' => [$required, 'string', 'max:255'],
            'branch_uid' => ['nullable', 'string', Rule::exists('branches', 'uid')],
            'offering_uid' => ['nullable', 'string', Rule::exists('course_offerings', 'uid')],
            'cycle_uid' => ['nullable', 'string', Rule::exists('admission_cycles', 'uid')],
            'valid_from' => [$required, 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'approval_powers' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        foreach (['branch_uid' => [Branch::class, 'branch_id'], 'offering_uid' => [CourseOffering::class, 'course_offering_id'], 'cycle_uid' => [AdmissionCycle::class, 'admission_cycle_id']] as $key => [$model, $column]) {
            if (array_key_exists($key, $data)) {
                $data[$column] = $data[$key] ? $model::where('uid', $data[$key])->value('id') : null;
                unset($data[$key]);
            }
        }

        // A team for one course offering takes its branch and cycle from that offering.
        $offeringId = $data['course_offering_id'] ?? $team?->course_offering_id;
        if ($offeringId) {
            $offering = CourseOffering::with('branchCourse')->find($offeringId);
            $branchId = $data['branch_id'] ?? $team?->branch_id;
            $cycleId = $data['admission_cycle_id'] ?? $team?->admission_cycle_id;
            if ($branchId && $branchId !== $offering->branchCourse->branch_id) {
                $this->fail('branch_uid', 'The branch does not match the course offering.');
            }
            if ($cycleId && $cycleId !== $offering->admission_cycle_id) {
                $this->fail('cycle_uid', 'The cycle does not match the course offering.');
            }
            $data['branch_id'] = $offering->branchCourse->branch_id;
            $data['admission_cycle_id'] = $offering->admission_cycle_id;
        }

        return $data;
    }

    private function ensureVisible(Team $team): void
    {
        $this->access()->authorizeAnywhere($this->actor(), 'teams.view');
        if (! $this->access()->canSee($this->actor(), 'teams.view', $team->scope())) {
            throw new NotFoundHttpException;
        }
    }

    private function summary(Team $team): array
    {
        $course = $team->offering?->branchCourse?->course;

        return [
            'uid' => $team->uid,
            'type' => $team->type,
            'name' => $team->name,
            'branch' => $team->branch ? ['uid' => $team->branch->uid, 'name' => $team->branch->name] : null,
            'offering' => $team->offering ? ['uid' => $team->offering->uid, 'course_name' => $course?->name, 'course_code' => $course?->code] : null,
            'cycle' => $team->cycle ? ['uid' => $team->cycle->uid, 'name' => $team->cycle->name, 'code' => $team->cycle->code] : null,
            'valid_from' => $team->valid_from?->toDateString(),
            'valid_to' => $team->valid_to?->toDateString(),
            'approval_powers' => $team->approval_powers,
            'status' => $team->status,
            'members_count' => $team->current_members_count ?? $team->currentMembers()->count(),
        ];
    }

    private function present(Team $team): array
    {
        $team->refresh()->load(['branch:id,uid,name', 'cycle:id,uid,name,code', 'offering.branchCourse.course:id,name,code']);

        return $this->summary($team) + [
            'can_manage' => $this->access()->can($this->actor(), 'teams.manage', $team->scope()),
            'members' => $this->memberRows($team),
        ];
    }

    private function memberRows(Team $team): array
    {
        return $team->members()->with('user:id,uid,name,email')->orderByRaw('removed_at is not null')->orderBy('id')->get()
            ->map(fn (TeamMember $m) => [
                'id' => $m->id,
                'user' => ['uid' => $m->user->uid, 'name' => $m->user->name, 'email' => $m->user->email],
                'member_role' => $m->member_role,
                'valid_from' => $m->valid_from?->toDateString(),
                'valid_to' => $m->valid_to?->toDateString(),
                'removed_at' => $m->removed_at?->toIso8601String(),
            ])->all();
    }
}

<?php

namespace App\Modules\Organisation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Organisation\Models\Institution;
use App\Modules\Organisation\Models\Location;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'organisation.view');

        $query = Branch::query()->with('location:id,uid,name,city')
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name');
        $this->access()->applyScope($query, $this->actor(), 'organisation.view', ['branch' => 'branches.id']);

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function show(Branch $branch): JsonResponse
    {
        $this->access()->authorizeSee($this->actor(), 'organisation.view', Scope::branch($branch->id));

        return response()->json(['data' => $branch->load('location:id,uid,name,city')]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'organisation.manage');
        $data = $this->validated($request);
        $institution = Institution::firstOrFail();

        $branch = DB::transaction(function () use ($data, $institution) {
            $branch = Branch::create($data + ['institution_id' => $institution->id]);
            $this->audit()->created('branch.created', $branch, Scope::branch($branch->id));

            return $branch;
        });

        return response()->json(['data' => $branch->fresh('location')], 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $scope = Scope::branch($branch->id);
        $this->access()->authorize($this->actor(), 'organisation.manage', $scope, 'organisation.view');
        $data = $this->validated($request, $branch);
        if (isset($data['code']) && $data['code'] !== $branch->code && $branch->isReferenced()) {
            $this->fail('code', 'The code cannot change once the branch is in use.');
        }

        DB::transaction(fn () => $this->audit()->update('branch.updated', $branch, $data, $scope));

        return response()->json(['data' => $branch->fresh('location')]);
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        $required = $branch ? 'sometimes' : 'required';
        $data = $request->validate([
            'code' => [$required, 'string', 'max:32', 'alpha_dash', Rule::unique('branches')->ignore($branch?->id)],
            'name' => [$required, 'string', 'max:255'],
            'location_uid' => ['nullable', 'string', Rule::exists('locations', 'uid')],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        if (array_key_exists('location_uid', $data)) {
            $data['location_id'] = $data['location_uid'] ? Location::where('uid', $data['location_uid'])->value('id') : null;
            unset($data['location_uid']);
        }

        return $data;
    }
}

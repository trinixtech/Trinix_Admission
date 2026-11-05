<?php

namespace App\Modules\Organisation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organisation\Models\Institution;
use App\Modules\Organisation\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'organisation.view');

        $query = Location::query()
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")->orWhere('city', 'like', "%$s%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name');

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function show(Location $location): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'organisation.view');

        return response()->json(['data' => $location]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'organisation.manage');
        $data = $this->validated($request);
        $institution = Institution::firstOrFail();

        $location = DB::transaction(function () use ($data, $institution) {
            $location = Location::create($data + ['institution_id' => $institution->id]);
            $this->audit()->created('location.created', $location);

            return $location;
        });

        return response()->json(['data' => $location->fresh()], 201);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'organisation.manage');
        $data = $this->validated($request, $location);
        if (isset($data['code']) && $data['code'] !== $location->code && $location->isReferenced()) {
            $this->fail('code', 'The code cannot change once branches use this location.');
        }

        DB::transaction(fn () => $this->audit()->update('location.updated', $location, $data));

        return response()->json(['data' => $location->fresh()]);
    }

    private function validated(Request $request, ?Location $location = null): array
    {
        $required = $location ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:32', 'alpha_dash', Rule::unique('locations')->ignore($location?->id)],
            'name' => [$required, 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'digits:6'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}

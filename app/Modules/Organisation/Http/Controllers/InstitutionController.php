<?php

namespace App\Modules\Organisation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organisation\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstitutionController extends Controller
{
    public function show(): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'organisation.view');

        return response()->json(['data' => Institution::first()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'organisation.manage');
        $institution = Institution::first() ?? new Institution;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:1000'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'],
        ]);

        DB::transaction(function () use ($institution, $data) {
            if ($institution->exists) {
                $this->audit()->update('institution.updated', $institution, $data);
            } else {
                $institution->fill($data)->save();
                $this->audit()->created('institution.created', $institution);
            }
        });

        return response()->json(['data' => $institution->fresh()]);
    }
}

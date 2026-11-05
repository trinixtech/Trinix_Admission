<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Imports\Models\ImportMappingTemplate;
use App\Modules\Imports\Services\FieldCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class SourceController extends Controller
{
    private function authorizeView(): void
    {
        if (! $this->access()->hasAnywhere($this->actor(), 'sources.manage')) {
            $this->access()->authorizeAnywhere($this->actor(), 'imports.view');
        }
    }

    public function index(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => ExternalSource::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'sources.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('external_sources')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $source = DB::transaction(function () use ($data) {
            $source = ExternalSource::create($data + ['status' => 'active']);
            $this->audit()->created('source.created', $source);

            return $source;
        });

        return response()->json(['data' => $source], 201);
    }

    public function update(Request $request, ExternalSource $source): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'sources.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        DB::transaction(fn () => $this->audit()->update('source.updated', $source, $data));

        return response()->json(['data' => $source->fresh()]);
    }

    public function tokens(ExternalSource $source): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'sources.manage');

        return response()->json(['data' => $source->tokens()->latest('id')->get(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at'])]);
    }

    /** The token text is returned once and never stored in readable form. */
    public function createToken(Request $request, ExternalSource $source): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'sources.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expires_on' => ['nullable', 'date', 'after:today'],
        ]);
        $token = DB::transaction(function () use ($source, $data) {
            $token = $source->createToken($data['name'], ['import:push'], isset($data['expires_on']) ? now()->parse($data['expires_on'])->endOfDay() : null);
            $this->audit()->record('source.token_created', $source, null, ['token' => $data['name'], 'expires_on' => $data['expires_on'] ?? null]);

            return $token;
        });

        return response()->json(['data' => ['id' => $token->accessToken->id, 'name' => $data['name'], 'token' => $token->plainTextToken]], 201);
    }

    public function revokeToken(int $id): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'sources.manage');
        $token = PersonalAccessToken::where('tokenable_type', ExternalSource::class)->findOrFail($id);
        DB::transaction(function () use ($token) {
            $this->audit()->record('source.token_revoked', $token->tokenable, ['token' => $token->name], null);
            $token->delete();
        });

        return response()->json(['message' => 'Token revoked.']);
    }

    public function fields(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => collect(FieldCatalogue::fields())->map(fn ($f, $k) => [
            'key' => $k, 'label' => $f['label'], 'group' => $f['group'], 'required' => $f['required'] ?? false,
        ])->values()]);
    }

    public function templates(ExternalSource $source): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => $source->templates()->orderBy('name')->get()]);
    }

    public function storeTemplate(Request $request, ExternalSource $source): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'imports.manage');
        $data = $this->validatedTemplate($request);
        $template = DB::transaction(function () use ($source, $data) {
            $t = ImportMappingTemplate::create($data + ['external_source_id' => $source->id, 'created_by' => $this->actor()->id]);
            $this->audit()->created('import_template.created', $t);

            return $t;
        });

        return response()->json(['data' => $template], 201);
    }

    public function updateTemplate(Request $request, ImportMappingTemplate $template): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'imports.manage');
        $data = $this->validatedTemplate($request, true);
        DB::transaction(fn () => $this->audit()->update('import_template.updated', $template, $data));

        return response()->json(['data' => $template->fresh()]);
    }

    private function validatedTemplate(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'column_map' => [$partial ? 'sometimes' : 'required', 'array'],
            'column_map.*' => ['nullable', 'string', 'max:255'],
            'value_map' => ['nullable', 'array'],
            'value_map.*' => ['array'],
            'value_map.*.*' => ['nullable', 'string', 'max:255'],
        ]);
        foreach (array_keys($data['column_map'] ?? []) as $field) {
            if (! FieldCatalogue::isKnown($field)) {
                $this->fail("column_map.$field", "Unknown system field \"$field\".");
            }
        }

        return $data;
    }
}

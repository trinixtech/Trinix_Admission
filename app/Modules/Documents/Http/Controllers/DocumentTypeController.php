<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Document types for the optional file import (ZIP and API). File names carry the type code, e.g.
 * PRTM0000001_CLASS10.pdf. Codes are entered by the admin; a suggested starting list can be loaded.
 */
class DocumentTypeController extends Controller
{
    public function index(): JsonResponse
    {
        if (! $this->access()->hasAnywhere($this->actor(), 'documents.manage')) {
            $this->access()->authorizeAnywhere($this->actor(), 'documents.view');
        }

        return response()->json(['data' => DocumentType::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'documents.manage');
        $data = $this->validated($request);
        $type = DB::transaction(function () use ($data) {
            $t = DocumentType::create($data + ['status' => 'active']);
            $this->audit()->created('document_type.created', $t);

            return $t;
        });

        return response()->json(['data' => $type], 201);
    }

    public function update(Request $request, DocumentType $documentType): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'documents.manage');
        $data = $this->validated($request, $documentType);
        DB::transaction(fn () => $this->audit()->update('document_type.updated', $documentType, $data));

        return response()->json(['data' => $documentType->fresh()]);
    }

    /** Adds the suggested types that are missing; never changes existing ones. */
    public function suggested(): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'documents.manage');
        $n = 0;
        DB::transaction(function () use (&$n) {
            foreach (config('documents.suggested_document_types') as $code => $name) {
                $type = DocumentType::firstOrNew(['code' => $code]);
                if (! $type->exists) {
                    $type->fill(['name' => $name, 'allowed_types' => ['pdf', 'jpeg', 'png'], 'max_size_kb' => 5120, 'status' => 'active'])->save();
                    $this->audit()->created('document_type.created', $type, null, 'Suggested list');
                    $n++;
                }
            }
        });

        return response()->json(['message' => "$n suggested document types added.", 'data' => DocumentType::orderBy('name')->get()]);
    }

    private function validated(Request $request, ?DocumentType $type = null): array
    {
        $req = $type ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$type ? 'prohibited' : 'required', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_]*$/', Rule::unique('document_types')],
            'name' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'allowed_types' => [$req, 'array', 'min:1'],
            'allowed_types.*' => [Rule::in(['pdf', 'jpeg', 'png'])],
            'max_size_kb' => [$req, 'integer', 'min:50', 'max:51200'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ], ['code.regex' => 'Use capital letters, digits and underscores, e.g. CLASS10.']);
    }
}

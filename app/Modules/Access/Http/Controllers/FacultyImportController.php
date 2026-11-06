<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\FacultyImport;
use App\Support\Rules\UploadedTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** People → Users → Import faculty: preview first, then confirm (Stage A). */
class FacultyImportController extends Controller
{
    public function __construct(private FacultyImport $import) {}

    public function preview(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'users.manage');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', new UploadedTable],
            'also_committee_member' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->import->preview($data['file'], $this->actor(), (bool) ($data['also_committee_member'] ?? false))]);
    }

    public function confirm(string $token): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'users.manage');
        $result = $this->import->confirm($token, $this->actor());
        $c = $result['counts'];

        return response()->json(['data' => $result, 'message' => sprintf('%d people added and invited, %d existing people given new roles, %d unchanged, %d with errors.',
            $c['created'] ?? 0, $c['role_added'] ?? 0, $c['unchanged'] ?? 0, $c['error'] ?? 0)]);
    }
}

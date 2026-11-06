<?php

namespace App\Modules\System\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\System\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** System → Backups. Backups hold all applicant data: only backups.manage (and backups.restore to restore). */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'backups.manage');

        return response()->json(['data' => [
            'settings' => $this->backups->settings(),
            'backups' => $this->backups->list(),
            'can_restore' => $this->access()->can($this->actor(), 'backups.restore'),
            'format' => BackupService::sealed() ? 'openssl' : 'zip',
            'compressed' => BackupService::canGzip(),
            'last_error' => BackupService::lastError(),
            'max_upload_mb' => (int) floor(min($this->bytes(ini_get('upload_max_filesize')), $this->bytes(ini_get('post_max_size'))) / 1048576),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'backups.manage');
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'time' => ['sometimes', 'date_format:H:i'],
            'keep' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'keep_full' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'full_day' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6'],
            'emails' => ['sometimes', 'array', 'max:3'],
            'emails.*' => ['email'],
            'email_limit_mb' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'password' => ['sometimes', 'string', 'min:12', 'max:128', 'confirmed'],
        ], ['password.min' => 'Use a backup password of at least 12 characters.']);
        unset($data['password_confirmation']);

        return $this->guard(fn () => response()->json(['data' => $this->backups->updateSettings($data, $this->actor()), 'message' => 'Backup settings saved.']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'backups.manage');
        $data = $request->validate(['kind' => ['required', Rule::in(['db', 'full'])], 'email' => ['sometimes', 'boolean']]);

        return $this->guard(function () use ($data) {
            $info = $this->backups->create($data['kind'], $this->actor());
            $made = 'Backup made ('.BackupService::humanSize($info['size']).')';
            if (! (($data['email'] ?? false) && $data['kind'] === 'db')) {
                return response()->json(['data' => $info, 'message' => "$made."], 201);
            }
            try {
                $sent = $this->backups->email($info, $this->actor());
            } catch (\Throwable $e) {
                // The backup is safe on the server; only the email failed (usually the SMTP settings).
                return response()->json(['data' => $info, 'message' => "$made, but the email could not be sent: ".class_basename($e).'. Check the mail settings (System → Health, or send a test email). The backup can be downloaded below.'], 201);
            }

            return response()->json(['data' => $info, 'message' => $made.($sent ? " and emailed to $sent ".($sent === 1 ? 'address' : 'addresses') : ' (no email addresses set, so not emailed)').'.'], 201);
        });
    }

    public function download(string $name): BinaryFileResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'backups.manage');
        try {
            $path = $this->backups->path($name);
        } catch (RuntimeException) {
            abort(404);
        }
        $this->audit()->record('backup.downloaded', null, null, ['name' => $name]);

        return response()->download($path, $name, ['Content-Type' => str_ends_with($name, '.zip') ? 'application/zip' : 'application/octet-stream']);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'backups.restore');
        $data = $request->validate(['file' => ['required', 'file', 'extensions:zip,enc'], 'backup_password' => ['nullable', 'string', 'max:128']]);

        return $this->guard(fn () => response()->json(['data' => $this->backups->upload($data['file']->getRealPath(), $data['backup_password'] ?? null, $this->actor()),
            'message' => 'The backup opens and can be restored.'], 201), 'file');
    }

    public function restore(Request $request, string $name): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'backups.restore');
        $data = $request->validate(['confirm' => ['required', 'in:RESTORE'], 'password' => ['required', 'string'], 'backup_password' => ['nullable', 'string', 'max:128']],
            ['confirm.in' => 'Type RESTORE to confirm.']);
        if (! Hash::check($data['password'], $this->actor()->password)) {
            $this->fail('password', 'Your password is not correct.');
        }

        return $this->guard(function () use ($name, $data) {
            $r = $this->backups->restore($name, $data['backup_password'] ?? null, $this->actor());

            return response()->json(['data' => $r, 'message' => "Restored {$r['tables']} tables".($r['documents'] ? " and {$r['documents']} documents" : '').". A safety backup of the data before the restore was kept ({$r['safety']}). Sign in again if asked."]);
        }, 'backup_password');
    }

    private function guard(callable $fn, string $field = 'backup'): JsonResponse
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            $this->fail($field, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->fail($field, 'The backup stopped: '.class_basename($e).' ('.Str::limit($e->getMessage(), 160).'). See storage/logs/laravel.log.');
        }
    }

    private function bytes(string|false $v): int
    {
        $v = trim((string) $v);
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n ?: PHP_INT_MAX,
        };
    }
}

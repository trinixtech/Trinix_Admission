<?php

namespace App\Http\Controllers;

use App\Modules\Access\Models\User;
use App\Modules\Access\Services\AccessControl;
use App\Modules\Audit\Services\Auditor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    protected function actor(): User
    {
        /** @var User */
        return request()->user();
    }

    protected function access(): AccessControl
    {
        return app(AccessControl::class);
    }

    protected function audit(): Auditor
    {
        return app(Auditor::class);
    }

    protected function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 25), 1), 100);
    }

    /** Throw a 422 with one message on one field. */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

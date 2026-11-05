<?php

namespace App\Modules\System\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\System\Services\HealthCheck;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /** Public, minimal: for the load balancer. 503 takes this server out of rotation. */
    public function up(HealthCheck $health): JsonResponse
    {
        $checks = $health->essentials();
        $ok = collect($checks)->every(fn ($c) => $c['ok']);

        return response()->json(['ok' => $ok, 'checks' => collect($checks)->map(fn ($c) => $c['ok'])], $ok ? 200 : 503);
    }

    public function show(HealthCheck $health): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'system.health');

        return response()->json(['data' => $health->details()]);
    }
}

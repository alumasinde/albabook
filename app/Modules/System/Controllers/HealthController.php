<?php

declare(strict_types=1);

namespace App\Modules\System\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\System\Services\HealthCheck;

/**
 * GET /health on every host. 200 when healthy, 503 otherwise, so load
 * balancers can act on the status code alone. No versions or internals are
 * exposed.
 */
final class HealthController
{
    public function __construct(private readonly HealthCheck $health) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $report = $this->health->run();

        return Response::json($report, $report['status'] === 'ok' ? 200 : 503)
            ->withHeader('Cache-Control', 'no-store');
    }
}

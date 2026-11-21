<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\System\Controllers\HomeController;

// API — api.albabook.co.ke. /v1/public/... and /v1/... arrive in Phase 9 (decisions.md D-008).
return static function (RouteRegistrar $r): void {
    $r->get('/', [HomeController::class, 'api'], name: 'api.home');
};

<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\System\Controllers\HomeController;

// Tenant portal — app.albabook.co.ke. Auth and dashboard routes arrive in Phase 2.
return static function (RouteRegistrar $r): void {
    $r->get('/', [HomeController::class, 'tenant'], name: 'tenant.home');
};

<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\System\Controllers\HomeController;

// Platform admin — admin.albabook.co.ke. Separate auth boundary arrives in Phase 2 (auth.md §10).
return static function (RouteRegistrar $r): void {
    $r->get('/', [HomeController::class, 'admin'], name: 'admin.home');
};

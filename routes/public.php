<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\System\Controllers\HomeController;

// Public booking — book.albabook.co.ke. /{slug} routes arrive in Phase 6 (decisions.md D-005).
return static function (RouteRegistrar $r): void {
    $r->get('/', [HomeController::class, 'booking'], name: 'booking.home');
};

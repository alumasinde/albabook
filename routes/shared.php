<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\System\Controllers\ComponentCatalogueController;
use App\Modules\System\Controllers\HealthController;

// Routes present on every host.
return static function (RouteRegistrar $r): void {
    $r->get('/health', [HealthController::class, 'show'], name: 'health');

    // Design-system review page. Returns 404 in production.
    $r->get('/_system/components', [ComponentCatalogueController::class, 'show'], name: 'system.components');
    $r->post('/_system/components/form', [ComponentCatalogueController::class, 'submit'], name: 'system.components.form');
};

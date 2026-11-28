<?php

declare(strict_types=1);

use App\Core\Http\HostGroup;
use App\Core\Routing\Router;

/*
 * One route file per host (plan.md §18). A route defined in one file can
 * never be reached from another host.
 */
return static function (Router $router, string $basePath): void {
    // Shared static routes are registered first: FastRoute rejects a static
    // route declared after a variable route that would shadow it (the booking
    // host's /{slug} pattern would otherwise shadow /health).
    $router->everyHost(require $basePath . '/routes/shared.php');

    $files = [
        'web.php' => HostGroup::Tenant,
        'public.php' => HostGroup::Booking,
        'api.php' => HostGroup::Api,
        'webhooks.php' => HostGroup::Api,
        'admin.php' => HostGroup::Admin,
        'site.php' => HostGroup::Marketing,
    ];

    foreach ($files as $file => $group) {
        $define = require $basePath . '/routes/' . $file;
        $router->group($group, $define);
    }
};

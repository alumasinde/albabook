<?php

declare(strict_types=1);

use App\Core\Http\HostGroup;
use App\Core\Routing\Router;

/*
 * One route file per host (plan.md §18). A route defined in one file can
 * never be reached from another host.
 */
return static function (Router $router, string $basePath): void {
    $files = [
        'web.php' => HostGroup::Tenant,
        'public.php' => HostGroup::Booking,
        'api.php' => HostGroup::Api,
        'admin.php' => HostGroup::Admin,
    ];

    foreach ($files as $file => $group) {
        $define = require $basePath . '/routes/' . $file;
        $router->group($group, $define);
    }

    $router->everyHost(require $basePath . '/routes/shared.php');
};

<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;

// Inbound provider webhooks (/webhooks/{provider}/{endpointUuid}) arrive in Phases 8–9.
// They will live on the API host, verified by signature, CSRF-exempt (plan.md §19.6).
return static function (RouteRegistrar $r): void {};

<?php

declare(strict_types=1);

use App\Middleware\AuthenticationMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\ErrorHandlingMiddleware;
use App\Middleware\LocaleMiddleware;
use App\Middleware\MaintenanceMiddleware;
use App\Middleware\PlatformAuthenticationMiddleware;
use App\Middleware\RequestIdMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\TrustedHostMiddleware;

/*
 * Order matters; the first entry is the outermost layer.
 *
 * RequestId first so every log line and error page carries the id.
 * SecurityHeaders next so error responses get the headers too.
 * ErrorHandling inside both, so everything below it renders safely.
 * TrustedHost before anything that depends on the host.
 *
 * Phase 2 adds AuthenticationMiddleware, TenantMiddleware and
 * PermissionMiddleware to the group stacks (multi-tenancy.md §3).
 */
return [
    'global' => [
        RequestIdMiddleware::class,
        SecurityHeadersMiddleware::class,
        ErrorHandlingMiddleware::class,
        TrustedHostMiddleware::class,
        MaintenanceMiddleware::class,
        LocaleMiddleware::class,
    ],
    'groups' => [
        // Authentication runs after CSRF so a state-changing request is
        // rejected before any session work, and before route middleware so
        // RequireAuth/Tenant/Permission can rely on AuthContext.
        'app' => [CsrfMiddleware::class, AuthenticationMiddleware::class],
        'book' => [CsrfMiddleware::class],
        'admin' => [CsrfMiddleware::class, PlatformAuthenticationMiddleware::class],
        'api' => [],
    ],
];

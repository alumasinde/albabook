<?php

declare(strict_types=1);

/*
 * Front controller. The web server document root is public/ (plan.md §29);
 * nothing outside this directory is reachable over HTTP.
 */

use App\Core\Config\ConfigurationException;

try {
    $boot = require __DIR__ . '/../bootstrap/app.php';
    $app = $boot();
} catch (ConfigurationException $e) {
    // Fail closed: never serve requests with an invalid configuration.
    // Details go to the server log only; the visitor sees a generic page.
    error_log('AlbaBook refused to start. ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'AlbaBook is not configured correctly. The error has been logged.';
    exit(1);
}

$app->run();

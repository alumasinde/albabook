<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

// Load .env for local database credentials. Immutable: values forced by
// phpunit.xml (APP_ENV, DB_DATABASE=albabook_test, hosts, …) and real
// environment variables (CI) always win.
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

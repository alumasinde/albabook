<?php

declare(strict_types=1);

/*
 * Concurrency worker (booking-engine.md §20): a separate PHP process that
 * attempts one booking through the real ManualBookingService stack — real
 * MySQL, real staff row lock, real transaction. The parent test spawns N of
 * these at the same slot and asserts exactly one wins.
 *
 *   php booking_worker.php <tenant_id> <branch_id> <staff_id> <service_id> <date> <time>
 *
 * Prints a single JSON line: {"code":"OK"} on success or {"code":"…"} for
 * the TransitionException apiCode / "ERROR" for anything else.
 */

foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=',
    'APP_HOST_TENANT' => 'app.albabook.test',
    'APP_HOST_BOOKING' => 'book.albabook.test',
    'APP_HOST_API' => 'api.albabook.test',
    'APP_HOST_ADMIN' => 'admin.albabook.test',
    'DB_DATABASE' => 'albabook_test',
    'SESSION_SECURE_COOKIE' => 'false',
    'TRUSTED_PROXIES' => '10.0.0.0/8',
] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

[$script, $tenantId, $branchId, $staffId, $serviceId, $date, $time] = $argv;
// Optional 8th arg pins the customer phone so N workers race the SAME match
// key (identity upsert race); default stays unique per process.
$customerPhone = $argv[7] ?? ('+2547' . str_pad((string) (getmypid() % 100000000), 8, '0', STR_PAD_LEFT));

$app = (require dirname(__DIR__, 4) . '/bootstrap/app.php')();
$service = $app->container()->get(App\Modules\Appointments\Services\ManualBookingService::class);

try {
    $service->create(
        (int) $tenantId,
        (int) $branchId,
        'Africa/Nairobi',
        (int) $staffId,
        (int) $serviceId,
        $date,
        $time,
        [
            'name' => 'Concurrent Booker',
            'phone' => $customerPhone,
            'email' => null,
            'notes' => null,
        ],
        'dashboard',
        'en',
        'KES',
        null,
        'tenant_user',
        true, // override policy — the race is about occupancy, not hours
    );
    echo json_encode(['code' => 'OK']);
} catch (App\Modules\Appointments\Services\TransitionException $e) {
    echo json_encode(['code' => $e->apiCode]);
} catch (\Throwable $e) {
    echo json_encode(['code' => 'ERROR', 'message' => $e->getMessage()]);
}

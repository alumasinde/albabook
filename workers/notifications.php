<?php

declare(strict_types=1);

/*
 * notifications worker — drains notification_queue (booking receipts,
 * confirmations, reminders) across email and the tenant's sms/whatsapp
 * provider account.
 *
 * Contract (multi-tenancy.md "Background workers"): jobs are claimed with
 * FOR UPDATE SKIP LOCKED in a short transaction; sends happen after commit
 * so a slow provider never holds row locks.
 */

use App\Core\Queue\Worker;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\System\Repositories\PlatformStateRepository;

$app = (require __DIR__ . '/../bootstrap/app.php')();

exit(Worker::run(
    'notifications',
    static fn(): int => $app->container()->get(NotificationDispatcher::class)->dispatchBatch(20),
    onPulse: static fn() => $app->container()->get(PlatformStateRepository::class)->touch('worker.notifications.last_run_at'),
));

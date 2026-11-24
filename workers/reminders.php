<?php

declare(strict_types=1);

/*
 * reminders worker — turns due scheduled_notifications rows into real
 * notification_queue jobs (24h / 2h appointment reminders). Stale rows
 * (appointment moved or ended) are skipped.
 */

use App\Core\Queue\Worker;
use App\Modules\Notifications\Services\ReminderService;
use App\Modules\System\Repositories\PlatformStateRepository;

$app = (require __DIR__ . '/../bootstrap/app.php')();

exit(Worker::run(
    'reminders',
    static fn(): int => $app->container()->get(ReminderService::class)->dispatchBatch(50),
    onPulse: static fn() => $app->container()->get(PlatformStateRepository::class)->touch('worker.reminders.last_run_at'),
));

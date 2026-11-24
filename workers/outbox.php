<?php

declare(strict_types=1);

/*
 * outbox worker — fans pending outbox_events out to webhook_deliveries
 * for every matching active endpoint (at-least-once; consumers dedupe on
 * the event UUID).
 */

use App\Core\Queue\Worker;
use App\Modules\Integrations\Services\OutboxDispatcher;
use App\Modules\System\Repositories\PlatformStateRepository;

$app = (require __DIR__ . '/../bootstrap/app.php')();

exit(Worker::run(
    'outbox',
    static fn(): int => $app->container()->get(OutboxDispatcher::class)->dispatchBatch(50),
    onPulse: static fn() => $app->container()->get(PlatformStateRepository::class)->touch('worker.outbox.last_run_at'),
));

<?php

declare(strict_types=1);

/*
 * outbox worker — fans pending outbox_events out to webhook_deliveries
 * for every matching active endpoint (at-least-once; consumers dedupe on
 * the event UUID).
 */

use App\Core\Queue\Worker;
use App\Modules\Integrations\Services\OutboxDispatcher;

$app = (require __DIR__ . '/../bootstrap/app.php')();

exit(Worker::run(
    'outbox',
    static fn(): int => $app->container()->get(OutboxDispatcher::class)->dispatchBatch(50),
));

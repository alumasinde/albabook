<?php

declare(strict_types=1);

/*
 * webhooks worker — POSTs pending webhook_deliveries to endpoint URLs,
 * signed with the endpoint secret (X-Albabook-Signature). Failing rows
 * retry up to five attempts, then stay 'failed' for review.
 */

use App\Core\Queue\Worker;
use App\Modules\Integrations\Services\WebhookSender;
use App\Modules\System\Repositories\PlatformStateRepository;

$app = (require __DIR__ . '/../bootstrap/app.php')();

exit(Worker::run(
    'webhooks',
    static fn(): int => $app->container()->get(WebhookSender::class)->dispatchBatch(20),
    onPulse: static fn() => $app->container()->get(PlatformStateRepository::class)->touch('worker.webhooks.last_run_at'),
));

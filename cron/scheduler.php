<?php

declare(strict_types=1);

/*
 * Cron entry — run every minute:
 *   * * * * * php /path/to/albabook/cron/scheduler.php
 *
 * One bounded pass over every queue plus the housekeeping sweep and the
 * night-audit reconciliation (stale pending -> expired, stale confirmed ->
 * no_show, judged against each tenant's local business day). For
 * continuous throughput run the workers/ scripts under a supervisor
 * instead.
 */

use App\Console\Commands\AuditDailyCommand;
use App\Console\Output;
use App\Modules\Integrations\Services\OutboxDispatcher;
use App\Modules\Integrations\Services\WebhookSender;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Notifications\Services\ReminderService;
use App\Modules\System\Services\CleanupService;

$app = (require __DIR__ . '/../bootstrap/app.php')();
$container = $app->container();

$quiet = new Output(fopen('php://temp', 'w'), fopen('php://temp', 'w'));

$housekeeping = $container->get(CleanupService::class);
$done = $housekeeping->expireHolds()
    + $housekeeping->expireVerificationCodes()
    + $housekeeping->recoverStuckNotifications();
$housekeeping->pruneRateLimits();
$housekeeping->pruneStaleDomainClaims($container->get(\App\Core\Config\Config::class)->int('tenancy.domain_claim_ttl_hours', 72));

$done += $container->get(ReminderService::class)->dispatchBatch(50)
    + $container->get(NotificationDispatcher::class)->dispatchBatch(50)
    + $container->get(OutboxDispatcher::class)->dispatchBatch(50)
    + $container->get(WebhookSender::class)->dispatchBatch(20);

$container->get(AuditDailyCommand::class)->run(['--apply'], $quiet);

fwrite(STDOUT, "scheduler: {$done} job(s) processed.\n");
exit(0);

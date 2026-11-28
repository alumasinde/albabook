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
use App\Modules\System\Repositories\PlatformStateRepository;
use App\Modules\System\Services\CleanupService;

$app = (require __DIR__ . '/../bootstrap/app.php')();
$container = $app->container();

$nullStream = fopen('php://temp', 'w');
$quiet = new Output($nullStream === false ? null : $nullStream, null);

$housekeeping = $container->get(CleanupService::class);
$done = $housekeeping->expireHolds()
    + $housekeeping->expireVerificationCodes()
    + $housekeeping->recoverStuckNotifications();
$housekeeping->pruneRateLimits();
$housekeeping->pruneStaleDomainClaims($container->get(\App\Core\Config\Config::class)->int('tenancy.domain_claim_ttl_hours', 72));

// Unapproved booking requests (manual confirmation) release their slot once
// the approval window lapses or the visit time arrives. Runs before the
// dispatchers so the customer's "request expired" notice goes out this pass.
$done += $container->get(\App\Modules\Appointments\Services\PendingExpiryService::class)->sweep(100);

// AlbaBook's own billing: trial reminders/expiry, renewal invoices, grace
// demotion and read-only expiry.
$done += $container->get(\App\Modules\Billing\Services\SubscriptionBillingService::class)->sweep(100);

$done += $container->get(ReminderService::class)->dispatchBatch(50)
    + $container->get(NotificationDispatcher::class)->dispatchBatch(50)
    + $container->get(OutboxDispatcher::class)->dispatchBatch(50)
    + $container->get(WebhookSender::class)->dispatchBatch(20)
    // M-Pesa prompts whose callback never arrived: settle via STK Query.
    + $container->get(\App\Modules\Payments\Mpesa\MpesaStkService::class)->sweepStale()
    // eTIMS: submit pending tax invoices to KRA.
    + $container->get(\App\Modules\Tax\Etims\EtimsService::class)->submitPending(20);

$container->get(AuditDailyCommand::class)->run(['--apply'], $quiet);

// Heartbeat consumed by /health ("signals.scheduler"). Written last so a run
// that dies mid-pass reports stale rather than looking alive.
$container->get(PlatformStateRepository::class)->touch(PlatformStateRepository::SCHEDULER_LAST_RUN);

fwrite(STDOUT, "scheduler: {$done} job(s) processed.\n");
exit(0);

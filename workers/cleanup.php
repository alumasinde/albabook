<?php

declare(strict_types=1);

/*
 * cleanup worker — periodic housekeeping: expire booking holds and OTP
 * codes, recover queue rows stuck 'processing' after a crashed send, and
 * prune old rate-limit buckets.
 */

use App\Core\Queue\Worker;
use App\Modules\System\Repositories\PlatformStateRepository;
use App\Modules\System\Services\CleanupService;

$app = (require __DIR__ . '/../bootstrap/app.php')();

exit(Worker::run(
    'cleanup',
    static function () use ($app): int {
        $cleanup = $app->container()->get(CleanupService::class);

        return $cleanup->expireHolds()
            + $cleanup->expireVerificationCodes()
            + $cleanup->recoverStuckNotifications()
            + (static function () use ($cleanup): int {
                $cleanup->pruneRateLimits();

                return 0;
            })();
    },
    maxSeconds: 10,
    idleSleepSeconds: 0,
    onPulse: static fn() => $app->container()->get(PlatformStateRepository::class)->touch('worker.cleanup.last_run_at'),
));

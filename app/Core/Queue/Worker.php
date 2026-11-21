<?php

declare(strict_types=1);

namespace App\Core\Queue;

/**
 * Shared loop for the workers in workers/. Each iteration runs one short
 * batch (jobs are claimed FOR UPDATE SKIP LOCKED in short transactions, so
 * multiple worker processes can run safely). The loop exits after
 * $maxSeconds so a supervisor or cron can restart it cleanly.
 */
final class Worker
{
    /**
     * @param callable(): int $batch returns the number of jobs processed in
     *        this pass; 0 means the queue was empty
     */
    public static function run(string $name, callable $batch, int $maxSeconds = 50, int $idleSleepSeconds = 2): int
    {
        $deadline = time() + $maxSeconds;
        $processed = 0;

        while (time() < $deadline) {
            try {
                $done = $batch();
            } catch (\Throwable $e) {
                fwrite(STDERR, "{$name}: batch failed — {$e->getMessage()}\n");
                sleep($idleSleepSeconds);
                continue;
            }

            $processed += $done;
            if ($done === 0) {
                sleep($idleSleepSeconds);
            }
        }

        fwrite(STDOUT, "{$name}: {$processed} job(s) processed.\n");

        return 0;
    }
}

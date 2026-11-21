<?php

declare(strict_types=1);

/*
 * Cron entry point, run every minute:
 *   * * * * * php /var/www/albabook/cron/scheduler.php
 *
 * Phase 4 schedules hold cleanup and the booking integrity check; Phase 8 the
 * reminder scheduler (plan.md §20.3). Each task will take a MySQL named lock
 * so overlapping cron runs cannot double-execute.
 */

$app = (require __DIR__ . '/../bootstrap/app.php')();

exit(0);

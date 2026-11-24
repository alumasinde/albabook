<?php

declare(strict_types=1);

return [
    // /health "signals": freshness thresholds for work done outside web
    // requests. Signals are informational — a stale one never changes the
    // HTTP status, because a sleeping cron or a missed backup does not mean
    // this node cannot serve traffic (a load balancer would wrongly drain
    // it). Alert on the signal values from external monitoring instead.

    // cron/scheduler.php runs every minute; flag it stale after 10 minutes.
    'scheduler_max_lag_seconds' => (int) env('OPS_SCHEDULER_MAX_LAG_SECONDS', 600),

    // docker/backup.sh runs nightly; flag backups stale after 26 hours.
    'backup_max_age_seconds' => (int) env('OPS_BACKUP_MAX_AGE_SECONDS', 93600),
];

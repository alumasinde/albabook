<?php

declare(strict_types=1);

/*
 * reminders worker — implemented in Phase 8 (plan.md §20.3).
 *
 * Contract every worker will follow (multi-tenancy.md "Background workers"):
 * - Claim jobs with SELECT ... FOR UPDATE SKIP LOCKED in short transactions.
 * - Build a fresh TenantContext per job from the job's stored tenant_id.
 * - Never hold a transaction open across an external HTTP call.
 * - Reset request-scoped state (RequestContext) between jobs.
 */

$app = (require __DIR__ . '/../bootstrap/app.php')();

fwrite(STDOUT, "reminders worker: not implemented until Phase 8.\n");
exit(0);

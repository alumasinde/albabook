<?php

declare(strict_types=1);

/*
 * cleanup worker — implemented in Phase 4 (plan.md §20.3).
 *
 * Contract every worker will follow (multi-tenancy.md "Background workers"):
 * - Claim jobs with SELECT ... FOR UPDATE SKIP LOCKED in short transactions.
 * - Build a fresh TenantContext per job from the job's stored tenant_id.
 * - Never hold a transaction open across an external HTTP call.
 * - Reset request-scoped state (RequestContext) between jobs.
 */

$app = (require __DIR__ . '/../bootstrap/app.php')();

fwrite(STDOUT, "cleanup worker: not implemented until Phase 4.\n");
exit(0);

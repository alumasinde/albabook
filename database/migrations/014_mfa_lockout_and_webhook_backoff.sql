-- Per-user two-factor lockout (audit fix): the per-session attempt cap could
-- be reset by simply logging in again. A cumulative per-user counter locks
-- verification for MFA_LOCK_MINUTES once USER_LOCK_ATTEMPTS is reached.
ALTER TABLE users
    ADD COLUMN mfa_failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN mfa_locked_until DATETIME NULL;

ALTER TABLE platform_users
    ADD COLUMN mfa_failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN mfa_locked_until DATETIME NULL;

-- Webhook delivery backoff: failed deliveries wait before re-delivery
-- instead of retrying all attempts within seconds.
ALTER TABLE webhook_deliveries
    ADD COLUMN next_attempt_at DATETIME NULL,
    ADD INDEX idx_webhook_deliveries_due (status, next_attempt_at, id);

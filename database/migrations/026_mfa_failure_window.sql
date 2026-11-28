-- Two-factor failure counting window (audit low): the per-user counter only
-- reset on a successful code, so occasional typos accumulated for weeks and a
-- later single mistake re-locked the account. Failures now count within a
-- fixed window that starts at the first failure and restarts once it lapses.
ALTER TABLE users
    ADD COLUMN mfa_failures_started_at DATETIME NULL AFTER mfa_failed_attempts;

ALTER TABLE platform_users
    ADD COLUMN mfa_failures_started_at DATETIME NULL AFTER mfa_failed_attempts;

-- Security hardening: TOTP brute-force protection.
--
-- Failed two-factor attempts are counted on the session row itself. After
-- MFA_MAX_ATTEMPTS (5) failures the session is revoked, forcing a fresh
-- password login before codes can be tried again.

ALTER TABLE user_sessions
    ADD COLUMN mfa_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE platform_sessions
    ADD COLUMN mfa_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;

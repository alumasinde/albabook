-- 030_subscription_billing.sql
-- AlbaBook's own subscription billing: scheduled plan changes (downgrades
-- apply at the next renewal, upgrades apply once a prorated invoice is
-- paid), a one-shot trial reminder flag, why an invoice exists (renewal,
-- upgrade, activation) and the Daraja checkout id behind a pending
-- subscription payment.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

ALTER TABLE tenant_subscriptions
    ADD COLUMN scheduled_plan_id BIGINT UNSIGNED NULL AFTER trial_ends_at,
    ADD COLUMN trial_reminder_sent_at DATETIME NULL AFTER scheduled_plan_id,
    ADD CONSTRAINT fk_tenant_subscriptions_scheduled_plan
        FOREIGN KEY (scheduled_plan_id) REFERENCES plans (id)
        ON DELETE RESTRICT;

ALTER TABLE subscription_invoices
    ADD COLUMN purpose ENUM('renewal', 'upgrade', 'activation')
        NOT NULL DEFAULT 'renewal' AFTER status;

ALTER TABLE subscription_payments
    ADD COLUMN checkout_request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
        AFTER provider_reference,
    ADD UNIQUE KEY uq_subscription_payments_checkout (checkout_request_id);

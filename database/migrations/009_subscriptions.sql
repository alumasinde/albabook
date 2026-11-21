-- 009_subscriptions.sql
-- AlbaBook: plans, tenant subscriptions, feature entitlements, usage and
-- AlbaTech's own invoices to tenants.
--
-- This is the tenant -> AlbaTech billing domain. It never shares tables with
-- customer payments (005_payments.sql). AlbaTech subscription invoices must be
-- eTIMS-compliant from launch (D-015).

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE plans (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code                VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name                VARCHAR(100) NOT NULL,
    description         VARCHAR(500) NULL,
    price_minor         BIGINT NOT NULL DEFAULT 0,
    currency            CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'KES',
    billing_interval    ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly',
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_uuid (uuid),
    UNIQUE KEY uq_plans_code (code),
    CONSTRAINT fk_plans_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),
    CONSTRAINT chk_plans_price CHECK (price_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plan_features (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id     BIGINT UNSIGNED NOT NULL,
    feature_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_enabled  BOOLEAN NOT NULL DEFAULT TRUE,
    limit_value INT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_plan_features_key (plan_id, feature_key),
    CONSTRAINT fk_plan_features_plan
        FOREIGN KEY (plan_id) REFERENCES plans (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_plan_features_feature
        FOREIGN KEY (feature_key) REFERENCES features (code),
    CONSTRAINT chk_plan_features_limit CHECK (limit_value IS NULL OR limit_value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_subscriptions (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    plan_id                 BIGINT UNSIGNED NOT NULL,
    status                  ENUM('trialing', 'active', 'past_due', 'cancelled', 'expired')
                                NOT NULL DEFAULT 'trialing',
    starts_at               DATETIME NOT NULL,
    current_period_start    DATETIME NOT NULL,
    current_period_end      DATETIME NOT NULL,
    trial_ends_at           DATETIME NULL,
    cancelled_at            DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_subscriptions_uuid (uuid),
    UNIQUE KEY uq_tenant_subscriptions_tenant_id (tenant_id, id),
    KEY idx_tenant_subscriptions_status (tenant_id, status),
    CONSTRAINT fk_tenant_subscriptions_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_subscriptions_plan
        FOREIGN KEY (plan_id) REFERENCES plans (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_tenant_subscriptions_period
        CHECK (current_period_end > current_period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_feature_overrides (
    tenant_id       BIGINT UNSIGNED NOT NULL,
    feature_key     VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_enabled      BOOLEAN NOT NULL DEFAULT TRUE,
    limit_value     INT NULL,
    expires_at      DATETIME NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (tenant_id, feature_key),
    CONSTRAINT fk_tenant_feature_overrides_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_feature_overrides_feature
        FOREIGN KEY (feature_key) REFERENCES features (code),
    CONSTRAINT chk_tenant_feature_overrides_limit
        CHECK (limit_value IS NULL OR limit_value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usage_records (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    metric_key      VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity        BIGINT NOT NULL DEFAULT 0,
    period_start    DATE NOT NULL,
    period_end      DATE NOT NULL,
    recorded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_usage_records_period (tenant_id, metric_key, period_start),
    CONSTRAINT fk_usage_records_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_usage_records_metric
        FOREIGN KEY (metric_key) REFERENCES features (code),
    CONSTRAINT chk_usage_records_quantity CHECK (quantity >= 0),
    CONSTRAINT chk_usage_records_period CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    event_type      VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload         JSON NULL,
    actor_type      ENUM('tenant_user', 'platform_user', 'system') NULL,
    actor_id        BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_subscription_events_subscription (tenant_id, subscription_id, created_at),
    CONSTRAINT fk_subscription_events_subscription
        FOREIGN KEY (tenant_id, subscription_id)
        REFERENCES tenant_subscriptions (tenant_id, id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_invoices (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    subscription_id     BIGINT UNSIGNED NOT NULL,
    invoice_number      VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status              ENUM('draft', 'issued', 'paid', 'void', 'overdue')
                            NOT NULL DEFAULT 'draft',
    issued_at           DATETIME NULL,
    due_at              DATETIME NULL,
    subtotal_minor      BIGINT NOT NULL DEFAULT 0,
    tax_minor           BIGINT NOT NULL DEFAULT 0,
    total_minor         BIGINT NOT NULL DEFAULT 0,
    currency            CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'KES',
    etims_reference     VARCHAR(100) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_subscription_invoices_uuid (uuid),
    UNIQUE KEY uq_subscription_invoices_tenant_id (tenant_id, id),
    UNIQUE KEY uq_subscription_invoices_number (invoice_number),
    KEY idx_subscription_invoices_tenant (tenant_id, status, issued_at),
    CONSTRAINT fk_subscription_invoices_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_invoices_subscription
        FOREIGN KEY (tenant_id, subscription_id)
        REFERENCES tenant_subscriptions (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_invoices_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),
    CONSTRAINT chk_subscription_invoices_money CHECK (
        subtotal_minor >= 0 AND tax_minor >= 0
        AND total_minor = subtotal_minor + tax_minor
    ),
    CONSTRAINT chk_subscription_invoices_issued CHECK (
        status = 'draft' OR issued_at IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_payments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    invoice_id          BIGINT UNSIGNED NOT NULL,
    amount_minor        BIGINT NOT NULL,
    currency            CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'KES',
    method              VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,  -- payment_methods where for_subscriptions
    provider_reference  VARCHAR(100) NULL,
    status              ENUM('pending', 'succeeded', 'failed', 'cancelled')
                            NOT NULL DEFAULT 'pending',
    paid_at             DATETIME NULL,
    recorded_by_user_id BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_subscription_payments_uuid (uuid),
    UNIQUE KEY uq_subscription_payments_tenant_id (tenant_id, id),
    KEY idx_subscription_payments_invoice (tenant_id, invoice_id),
    KEY idx_subscription_payments_status (status, paid_at),
    CONSTRAINT fk_subscription_payments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_payments_invoice
        FOREIGN KEY (tenant_id, invoice_id)
        REFERENCES subscription_invoices (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_payments_recorded_by
        FOREIGN KEY (recorded_by_user_id) REFERENCES platform_users (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_subscription_payments_method
        FOREIGN KEY (method) REFERENCES payment_methods (code),
    CONSTRAINT fk_subscription_payments_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),
    CONSTRAINT chk_subscription_payments_amount CHECK (amount_minor > 0),
    CONSTRAINT chk_subscription_payments_paid CHECK (
        status <> 'succeeded' OR paid_at IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 005_payments.sql
-- AlbaBook: customer payments to a tenant, refunds, provider callbacks and the
-- tenant's own M-Pesa collection accounts.
--
-- Customer payments (this file) never share tables with AlbaTech's subscription
-- billing (009_subscriptions.sql). Money is integer minor units (D-006).
-- An unmatched C2B payment is a payments row with status 'unmatched' (D-022).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE tenant_payment_accounts (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    branch_id               BIGINT UNSIGNED NULL,
    provider                VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_type            ENUM('till', 'paybill') NOT NULL,
    shortcode               VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name            VARCHAR(120) NOT NULL,
    credentials_encrypted   VARBINARY(2048) NULL,
    status                  ENUM('active', 'pending_golive', 'disabled') NOT NULL DEFAULT 'pending_golive',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_payment_accounts_uuid (uuid),
    UNIQUE KEY uq_tenant_payment_accounts_tenant_id (tenant_id, id),
    UNIQUE KEY uq_tenant_payment_accounts_shortcode
        (tenant_id, provider, account_type, shortcode),
    KEY idx_tenant_payment_accounts_status (tenant_id, status),
    CONSTRAINT fk_tenant_payment_accounts_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_payment_accounts_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_payment_accounts_provider
        FOREIGN KEY (provider) REFERENCES payment_providers (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    appointment_id      BIGINT UNSIGNED NULL,
    customer_id         BIGINT UNSIGNED NULL,

    kind                ENUM('deposit', 'balance', 'full', 'other') NOT NULL DEFAULT 'full',
    method              VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider            VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,  -- set when an integration created the row
    -- Transaction code (e.g. M-Pesa receipt). Upper-cased by PaymentService.
    provider_reference  VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    amount_minor        BIGINT NOT NULL,
    currency            CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status              ENUM('pending', 'succeeded', 'failed', 'cancelled', 'unmatched')
                            NOT NULL DEFAULT 'pending',
    received_at         DATETIME NULL,
    recorded_by_user_id BIGINT UNSIGNED NULL,
    idempotency_key     VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,

    match_state         ENUM('n/a', 'unmatched', 'matched', 'dismissed') NOT NULL DEFAULT 'n/a',
    matched_by_user_id  BIGINT UNSIGNED NULL,
    matched_at          DATETIME NULL,

    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_uuid (uuid),
    UNIQUE KEY uq_payments_tenant_id (tenant_id, id),
    -- Keyed on method, not provider: a receptionist typing an M-Pesa code
    -- (provider NULL) and the C2B callback for the same code (provider
    -- 'mpesa_daraja') collide here, so the callback reconciles with the manual
    -- entry instead of double-counting revenue.
    UNIQUE KEY uq_payments_method_reference (tenant_id, method, provider_reference),
    UNIQUE KEY uq_payments_idempotency (tenant_id, idempotency_key),
    KEY idx_payments_tenant_received (tenant_id, status, received_at),
    KEY idx_payments_appointment (tenant_id, appointment_id),
    KEY idx_payments_unmatched (tenant_id, status, match_state),

    CONSTRAINT fk_payments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_payments_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_payments_appointment
        FOREIGN KEY (tenant_id, appointment_id)
        REFERENCES appointments (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_payments_customer
        FOREIGN KEY (tenant_id, customer_id)
        REFERENCES customers (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_payments_recorded_by
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_payments_matched_by
        FOREIGN KEY (matched_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_payments_method
        FOREIGN KEY (method) REFERENCES payment_methods (code),
    CONSTRAINT fk_payments_provider
        FOREIGN KEY (provider) REFERENCES payment_providers (code),
    CONSTRAINT fk_payments_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),

    CONSTRAINT chk_payments_amount CHECK (amount_minor >= 0),
    CONSTRAINT chk_payments_unmatched_shape CHECK (
        status <> 'unmatched'
        OR (appointment_id IS NULL AND match_state = 'unmatched')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    payment_id              BIGINT UNSIGNED NOT NULL,
    amount_minor            BIGINT NOT NULL,
    currency                CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status                  ENUM('pending', 'succeeded', 'failed', 'cancelled')
                                NOT NULL DEFAULT 'pending',
    reason                  VARCHAR(255) NULL,
    refunded_at             DATETIME NULL,
    recorded_by_user_id     BIGINT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_refunds_uuid (uuid),
    UNIQUE KEY uq_refunds_tenant_id (tenant_id, id),
    KEY idx_refunds_payment (tenant_id, payment_id),
    KEY idx_refunds_tenant_refunded (tenant_id, status, refunded_at),
    CONSTRAINT fk_refunds_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_payment
        FOREIGN KEY (tenant_id, payment_id)
        REFERENCES payments (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_recorded_by
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_refunds_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),
    CONSTRAINT chk_refunds_amount CHECK (amount_minor > 0),
    CONSTRAINT chk_refunds_status_time CHECK (
        status <> 'succeeded' OR refunded_at IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_provider_events (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NULL,
    provider            VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_event_id   VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signature_valid     BOOLEAN NOT NULL DEFAULT FALSE,
    payload             JSON NOT NULL,
    received_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at        DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_provider_events_event (provider, provider_event_id),
    KEY idx_provider_events_tenant (tenant_id, received_at),
    CONSTRAINT fk_provider_events_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_provider_events_provider
        FOREIGN KEY (provider) REFERENCES payment_providers (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

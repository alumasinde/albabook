-- 004_booking_engine.sql
-- AlbaBook: appointments, per-item occupancy, resource allocations, event log,
-- holds, idempotency, reminders and the transactional outbox.
--
-- Every time-blocking write goes through BookingService (invariant I3).
-- Intervals are half-open [start, end); occupancies include buffers.
-- Appointment instants are UTC; the branch timezone is applied at the edges.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE schedule_versions (
    tenant_id   BIGINT UNSIGNED NOT NULL,
    branch_id   BIGINT UNSIGNED NOT NULL,
    local_date  DATE NOT NULL,
    version     INT UNSIGNED NOT NULL DEFAULT 1,

    PRIMARY KEY (tenant_id, branch_id, local_date),
    CONSTRAINT fk_schedule_versions_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id                   BIGINT UNSIGNED NOT NULL,
    branch_id                   BIGINT UNSIGNED NOT NULL,
    customer_id                 BIGINT UNSIGNED NULL,

    reference                   CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Lookup FK (appointment_statuses carries blocks_time / is_terminal, and the
    -- same codes are referenced by appointment_events). payment_status and
    -- booking_source stay ENUMs: single-table, code-driven, no metadata (D-023).
    status                      VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    payment_status              ENUM(
                                    'not_required',
                                    'unpaid',
                                    'deposit_paid',
                                    'partially_paid',
                                    'paid',
                                    'partially_refunded',
                                    'refunded'
                                ) NOT NULL DEFAULT 'unpaid',
    booking_source              ENUM(
                                    'public_page',
                                    'widget',
                                    'api',
                                    'dashboard',
                                    'walk_in',
                                    'import'
                                ) NOT NULL,

    start_at                    DATETIME NOT NULL,
    end_at                      DATETIME NOT NULL,
    staff_id                    BIGINT UNSIGNED NULL,   -- primary staff for list views only

    customer_name_snapshot      VARCHAR(255) NOT NULL,
    customer_phone_snapshot     VARCHAR(20) NULL,
    customer_email_snapshot     VARCHAR(254) NULL,
    locale                      VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'en',

    total_amount_minor          BIGINT NOT NULL DEFAULT 0,
    deposit_amount_minor        BIGINT NOT NULL DEFAULT 0,
    deposit_due_at              DATETIME NULL,
    currency                    CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

    policy_version              INT UNSIGNED NOT NULL,
    version                     INT UNSIGNED NOT NULL DEFAULT 1,
    reschedule_count            TINYINT UNSIGNED NOT NULL DEFAULT 0,

    cancelled_by                ENUM('customer', 'business', 'system') NULL,
    cancellation_reason         VARCHAR(255) NULL,
    cancelled_at                DATETIME NULL,
    is_late_cancellation        BOOLEAN NOT NULL DEFAULT FALSE,

    notes                       TEXT NULL,
    created_by_user_id          BIGINT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_appointments_uuid (uuid),
    UNIQUE KEY uq_appointments_tenant_id (tenant_id, id),
    UNIQUE KEY uq_appointments_tenant_reference (tenant_id, reference),
    KEY idx_appointments_tenant_start (tenant_id, start_at),
    KEY idx_appointments_tenant_branch_start (tenant_id, branch_id, start_at),
    KEY idx_appointments_tenant_status_start (tenant_id, status, start_at),
    KEY idx_appointments_tenant_customer_start (tenant_id, customer_id, start_at),

    CONSTRAINT fk_appointments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_appointments_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_appointments_customer
        FOREIGN KEY (tenant_id, customer_id)
        REFERENCES customers (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_appointments_primary_staff
        FOREIGN KEY (tenant_id, staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_appointments_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_appointments_status
        FOREIGN KEY (status) REFERENCES appointment_statuses (code),
    CONSTRAINT fk_appointments_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),
    CONSTRAINT fk_appointments_locale
        FOREIGN KEY (locale) REFERENCES locales (code),

    CONSTRAINT chk_appointments_window CHECK (end_at > start_at),
    CONSTRAINT chk_appointments_money CHECK (
        total_amount_minor >= 0 AND deposit_amount_minor >= 0
        AND deposit_amount_minor <= total_amount_minor
    ),
    CONSTRAINT chk_appointments_cancelled CHECK (
        status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_items (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    appointment_id          BIGINT UNSIGNED NOT NULL,
    item_position           TINYINT UNSIGNED NOT NULL,

    service_id              BIGINT UNSIGNED NOT NULL,
    variant_id              BIGINT UNSIGNED NULL,
    staff_id                BIGINT UNSIGNED NOT NULL,

    service_name_snapshot   VARCHAR(150) NOT NULL,
    variant_name_snapshot   VARCHAR(120) NULL,
    price_type              ENUM('fixed', 'from', 'range') NOT NULL,
    unit_amount_minor       BIGINT NOT NULL DEFAULT 0,
    min_amount_minor        BIGINT NULL,
    max_amount_minor        BIGINT NULL,
    quantity                SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    duration_minutes        SMALLINT UNSIGNED NOT NULL,
    buffer_before_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    buffer_after_minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    start_at                DATETIME NOT NULL,
    end_at                  DATETIME NOT NULL,
    occupied_start_at       DATETIME NOT NULL,
    occupied_end_at         DATETIME NOT NULL,

    addons_snapshot         JSON NULL,
    tax_rate_bp             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tax_amount_minor        BIGINT NOT NULL DEFAULT 0,
    currency                CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    policy_version          INT UNSIGNED NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_appointment_items_tenant_id (tenant_id, id),
    UNIQUE KEY uq_appointment_items_position (appointment_id, item_position),
    KEY idx_items_appointment (tenant_id, appointment_id, item_position),
    KEY idx_items_staff_occupancy (tenant_id, staff_id, occupied_start_at, occupied_end_at),

    CONSTRAINT fk_appointment_items_appointment
        FOREIGN KEY (tenant_id, appointment_id)
        REFERENCES appointments (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_appointment_items_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES services (tenant_id, id)
        ON DELETE RESTRICT,
    -- (tenant_id, service_id, variant_id): the variant must belong to the item's service.
    CONSTRAINT fk_appointment_items_variant
        FOREIGN KEY (tenant_id, service_id, variant_id)
        REFERENCES service_variants (tenant_id, service_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_appointment_items_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),
    CONSTRAINT fk_appointment_items_staff
        FOREIGN KEY (tenant_id, staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_appointment_items_window CHECK (end_at > start_at),
    CONSTRAINT chk_appointment_items_occupied CHECK (
        occupied_start_at <= start_at AND occupied_end_at >= end_at
    ),
    CONSTRAINT chk_appointment_items_money CHECK (
        unit_amount_minor >= 0 AND tax_amount_minor >= 0
        AND tax_amount_minor <= unit_amount_minor * quantity
    ),
    -- Same shape rule as services.price_type, written NULL-safe.
    CONSTRAINT chk_appointment_items_price_shape CHECK (
        (price_type = 'fixed' AND min_amount_minor IS NULL AND max_amount_minor IS NULL)
        OR (price_type = 'from' AND min_amount_minor IS NOT NULL AND min_amount_minor >= 0
            AND max_amount_minor IS NULL)
        OR (price_type = 'range' AND min_amount_minor IS NOT NULL AND min_amount_minor >= 0
            AND max_amount_minor IS NOT NULL AND max_amount_minor >= min_amount_minor)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_resources (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    appointment_item_id BIGINT UNSIGNED NOT NULL,
    resource_id         BIGINT UNSIGNED NOT NULL,
    quantity            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    occupied_start_at   DATETIME NOT NULL,
    occupied_end_at     DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_appointment_resources_tenant_id (tenant_id, id),
    KEY idx_alloc_item (tenant_id, appointment_item_id),
    KEY idx_alloc_resource_occupancy (tenant_id, resource_id, occupied_start_at, occupied_end_at),

    CONSTRAINT fk_appointment_resources_item
        FOREIGN KEY (tenant_id, appointment_item_id)
        REFERENCES appointment_items (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_appointment_resources_resource
        FOREIGN KEY (tenant_id, resource_id)
        REFERENCES resources (tenant_id, id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_appointment_resources_window CHECK (occupied_end_at > occupied_start_at),
    CONSTRAINT chk_appointment_resources_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    appointment_id  BIGINT UNSIGNED NOT NULL,
    event_type      VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_type      ENUM(
                        'tenant_user',
                        'platform_user',
                        'support_session',
                        'api_credential',
                        'system',
                        'customer'
                    ) NULL,
    actor_id        BIGINT UNSIGNED NULL,
    from_status     VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    to_status       VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason          VARCHAR(255) NULL,
    metadata        JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_appointment_events_appointment (tenant_id, appointment_id, created_at),
    CONSTRAINT fk_appointment_events_appointment
        FOREIGN KEY (tenant_id, appointment_id)
        REFERENCES appointments (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_appointment_events_from_status
        FOREIGN KEY (from_status) REFERENCES appointment_statuses (code),
    CONSTRAINT fk_appointment_events_to_status
        FOREIGN KEY (to_status) REFERENCES appointment_statuses (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_access_tokens (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    appointment_id  BIGINT UNSIGNED NOT NULL,
    token_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    purpose         ENUM('manage') NOT NULL DEFAULT 'manage',
    expires_at      DATETIME NOT NULL,
    revoked_at      DATETIME NULL,
    last_used_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_appointment_tokens_hash (token_hash),
    KEY idx_appointment_tokens_appointment (tenant_id, appointment_id, purpose),
    CONSTRAINT fk_appointment_tokens_appointment
        FOREIGN KEY (tenant_id, appointment_id)
        REFERENCES appointments (tenant_id, id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_verification_codes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    branch_id       BIGINT UNSIGNED NULL,
    contact_type    ENUM('phone', 'email') NOT NULL,
    contact_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    purpose         ENUM('booking', 'manage_lookup') NOT NULL DEFAULT 'booking',
    code_hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at      DATETIME NOT NULL,
    consumed_at     DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_booking_verification_lookup (tenant_id, contact_hash, purpose, expires_at),
    KEY idx_booking_verification_expiry (expires_at),
    CONSTRAINT fk_booking_verification_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_booking_verification_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_booking_verification_attempts
        CHECK (attempts <= 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_holds (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    branch_id               BIGINT UNSIGNED NOT NULL,
    token_hash              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status                  ENUM('active', 'converted', 'released', 'expired')
                                NOT NULL DEFAULT 'active',
    expires_at              DATETIME NOT NULL,
    request_hash            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_fingerprint      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    extended_count          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    converted_appointment_id BIGINT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_holds_tenant_id (tenant_id, id),
    UNIQUE KEY uq_booking_holds_token (token_hash),
    KEY idx_booking_holds_expiry (tenant_id, status, expires_at),
    CONSTRAINT fk_booking_holds_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_booking_holds_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_booking_holds_appointment
        FOREIGN KEY (tenant_id, converted_appointment_id)
        REFERENCES appointments (tenant_id, id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_hold_items (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    hold_id             BIGINT UNSIGNED NOT NULL,
    item_position       TINYINT UNSIGNED NOT NULL,
    staff_id            BIGINT UNSIGNED NOT NULL,
    occupied_start_at   DATETIME NOT NULL,
    occupied_end_at     DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_hold_items_position (hold_id, item_position),
    KEY idx_hold_items_staff_occupancy (tenant_id, staff_id, occupied_start_at, occupied_end_at),
    CONSTRAINT fk_booking_hold_items_hold
        FOREIGN KEY (tenant_id, hold_id)
        REFERENCES booking_holds (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_booking_hold_items_staff
        FOREIGN KEY (tenant_id, staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_booking_hold_items_window CHECK (occupied_end_at > occupied_start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_hold_resources (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    hold_id             BIGINT UNSIGNED NOT NULL,
    item_position       TINYINT UNSIGNED NOT NULL,
    resource_id         BIGINT UNSIGNED NOT NULL,
    occupied_start_at   DATETIME NOT NULL,
    occupied_end_at     DATETIME NOT NULL,

    PRIMARY KEY (id),
    KEY idx_hold_resources_hold (tenant_id, hold_id, item_position),
    KEY idx_hold_resources_occupancy (tenant_id, resource_id, occupied_start_at, occupied_end_at),
    CONSTRAINT fk_booking_hold_resources_hold
        FOREIGN KEY (tenant_id, hold_id)
        REFERENCES booking_holds (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_booking_hold_resources_resource
        FOREIGN KEY (tenant_id, resource_id)
        REFERENCES resources (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_booking_hold_resources_window CHECK (occupied_end_at > occupied_start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_idempotency_keys (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    scope           VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status          ENUM('processing', 'completed') NOT NULL DEFAULT 'processing',
    response_code   SMALLINT UNSIGNED NULL,
    response_body   JSON NULL,
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_idempotency (tenant_id, scope, idempotency_key),
    KEY idx_booking_idempotency_expiry (expires_at),
    CONSTRAINT fk_booking_idempotency_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduled_notifications (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    appointment_id      BIGINT UNSIGNED NOT NULL,
    appointment_version INT UNSIGNED NOT NULL,
    kind                VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,  -- notification_events.code where is_reminder
    send_at             DATETIME NOT NULL,
    status              ENUM('pending', 'processing', 'sent', 'failed', 'cancelled', 'skipped')
                            NOT NULL DEFAULT 'pending',
    attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error          VARCHAR(500) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at             DATETIME NULL,

    PRIMARY KEY (id),
    KEY idx_scheduled_notifications_due (status, send_at, id),
    KEY idx_scheduled_notifications_appointment (tenant_id, appointment_id),
    CONSTRAINT fk_scheduled_notifications_appointment
        FOREIGN KEY (tenant_id, appointment_id)
        REFERENCES appointments (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_scheduled_notifications_kind
        FOREIGN KEY (kind) REFERENCES notification_events (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE outbox_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id       BIGINT UNSIGNED NULL,
    event_type      VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aggregate_type  VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aggregate_id    BIGINT UNSIGNED NOT NULL,
    payload         JSON NOT NULL,
    status          ENUM('pending', 'processing', 'delivered', 'failed')
                        NOT NULL DEFAULT 'pending',
    available_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error      TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at    DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_outbox_events_uuid (uuid),
    KEY idx_outbox_events_dispatch (status, available_at, id),
    KEY idx_outbox_events_tenant (tenant_id, created_at),
    CONSTRAINT fk_outbox_events_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

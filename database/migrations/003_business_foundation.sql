-- 003_business_foundation.sql
-- AlbaBook: branches, hours, booking policy, service catalogue, staff,
-- resources, schedules, leave/blocks, customers and consent.
--
-- Schedule rules (opening hours, shifts, breaks) are local wall-clock TIME
-- values; leave, blocks and appointments are UTC DATETIME instants
-- (booking-engine.md §3).
-- schedule_overrides and blocked_periods use typed nullable scope columns with
-- composite tenant FKs, not polymorphic scope_type/scope_id (decisions.md D-021).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE business_categories (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name            VARCHAR(120) NOT NULL,
    sort_position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (id),
    UNIQUE KEY uq_business_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE branches (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,

    address_line1   VARCHAR(255) NULL,
    address_line2   VARCHAR(255) NULL,
    county          VARCHAR(100) NULL,
    town            VARCHAR(100) NULL,
    phone           VARCHAR(30) NULL,
    email           VARCHAR(254) NULL,

    -- NULL inherits the tenant timezone (booking-engine.md §3).
    timezone        VARCHAR(64) NULL,

    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_branches_uuid (uuid),
    UNIQUE KEY uq_branches_tenant_id (tenant_id, id),
    KEY idx_branches_tenant_active (tenant_id, is_active),
    CONSTRAINT fk_branches_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_categories (
    tenant_id               BIGINT UNSIGNED NOT NULL,
    business_category_id    BIGINT UNSIGNED NOT NULL,
    is_primary              BOOLEAN NOT NULL DEFAULT FALSE,

    PRIMARY KEY (tenant_id, business_category_id),
    CONSTRAINT fk_tenant_categories_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_categories_category
        FOREIGN KEY (business_category_id) REFERENCES business_categories (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership_branches (
    tenant_id       BIGINT UNSIGNED NOT NULL,
    tenant_user_id  BIGINT UNSIGNED NOT NULL,
    branch_id       BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (tenant_id, tenant_user_id, branch_id),
    CONSTRAINT fk_membership_branches_membership
        FOREIGN KEY (tenant_id, tenant_user_id)
        REFERENCES tenant_users (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_membership_branches_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE business_hours (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    branch_id       BIGINT UNSIGNED NOT NULL,
    weekday         TINYINT UNSIGNED NOT NULL,        -- ISO-8601: 1 = Monday ... 7 = Sunday
    opens_at        TIME NOT NULL,
    closes_at       TIME NOT NULL,                    -- closes_at <= opens_at means overnight
    effective_from  DATE NULL,
    effective_to    DATE NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_business_hours_branch (tenant_id, branch_id, weekday),
    CONSTRAINT fk_business_hours_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_business_hours_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_business_hours_times CHECK (opens_at <> closes_at),
    CONSTRAINT chk_business_hours_effective
        CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Policy columns are NULLable with no defaults: NULL means "inherit". A branch
-- row inherits the tenant row column by column; a tenant row inherits
-- config/booking.php (booking-engine.md §4.3). Defaults here would silently
-- freeze platform defaults into every row and break inheritance.
-- The engine-facing options stay ENUMs: code branches on every value, they are
-- used only here, and CHECK constraints depend on them (D-023).
-- fk_booking_policies_branch is RESTRICT because branch_id is the base column
-- of the STORED generated branch_scope; MySQL rejects CASCADE there. Branches
-- are deactivated, not deleted.
CREATE TABLE booking_policies (
    id                                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id                           BIGINT UNSIGNED NOT NULL,
    branch_id                           BIGINT UNSIGNED NULL,

    slot_interval_minutes               SMALLINT UNSIGNED NULL,
    min_notice_minutes                  INT UNSIGNED NULL,
    max_advance_days                    SMALLINT UNSIGNED NULL,
    hold_ttl_seconds                    INT UNSIGNED NULL,
    confirmation_mode                   ENUM('auto', 'manual') NULL,
    pending_expiry_minutes              INT UNSIGNED NULL,
    staff_assignment_strategy           ENUM('least_booked_day', 'round_robin', 'priority') NULL,
    customer_changes                    ENUM('self_service', 'request', 'disabled') NULL,
    cancellation_cutoff_minutes         INT UNSIGNED NULL,
    reschedule_cutoff_minutes           INT UNSIGNED NULL,
    max_reschedules                     TINYINT UNSIGNED NULL,
    deposit_mode                        ENUM('none', 'fixed', 'percent') NULL,
    deposit_value                       BIGINT NULL,
    deposit_due_minutes                 INT UNSIGNED NULL,
    customer_verification               ENUM('none', 'otp_phone', 'otp_email') NULL,
    max_active_bookings_per_contact     TINYINT UNSIGNED NULL,
    customer_match                      ENUM('phone', 'email', 'phone_or_email', 'none') NULL,
    policy_version                      INT UNSIGNED NOT NULL DEFAULT 1,

    branch_scope                        BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(branch_id, 0)) STORED,

    created_at                          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_policies_scope (tenant_id, branch_scope),
    CONSTRAINT fk_booking_policies_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_booking_policies_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_booking_policies_slot_interval
        CHECK (slot_interval_minutes IS NULL OR slot_interval_minutes BETWEEN 5 AND 240),
    -- deposit_mode and deposit_value are set (or inherited) together.
    -- Explicit IS NOT NULL: a CHECK that evaluates to NULL counts as passing,
    -- so `deposit_value > 0` alone would accept 'fixed' with no amount.
    CONSTRAINT chk_booking_policies_deposit CHECK (
        (deposit_mode IS NULL AND deposit_value IS NULL)
        OR (deposit_mode = 'none' AND deposit_value IS NULL)
        OR (deposit_mode = 'fixed' AND deposit_value IS NOT NULL AND deposit_value > 0)
        OR (deposit_mode = 'percent' AND deposit_value IS NOT NULL
            AND deposit_value BETWEEN 1 AND 10000)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_categories (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(120) NOT NULL,
    description     VARCHAR(500) NULL,
    sort_position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_service_categories_uuid (uuid),
    UNIQUE KEY uq_service_categories_tenant_id (tenant_id, id),
    KEY idx_service_categories_tenant (tenant_id, is_active, sort_position),
    CONSTRAINT fk_service_categories_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id                   BIGINT UNSIGNED NOT NULL,
    service_category_id         BIGINT UNSIGNED NULL,

    name                        VARCHAR(150) NOT NULL,
    description                 TEXT NULL,
    duration_minutes            SMALLINT UNSIGNED NOT NULL,
    buffer_before_minutes       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    buffer_after_minutes        SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    price_type                  ENUM('fixed', 'from', 'range') NOT NULL DEFAULT 'fixed',
    price_amount_minor          BIGINT NULL,
    min_amount_minor            BIGINT NULL,
    max_amount_minor            BIGINT NULL,
    currency                    CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    is_bookable_online          BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_services_uuid (uuid),
    UNIQUE KEY uq_services_tenant_id (tenant_id, id),
    KEY idx_services_tenant (tenant_id, is_active, sort_position),
    KEY idx_services_category (tenant_id, service_category_id),
    CONSTRAINT fk_services_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_services_category
        FOREIGN KEY (tenant_id, service_category_id)
        REFERENCES service_categories (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_services_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),
    CONSTRAINT chk_services_duration CHECK (duration_minutes > 0),
    CONSTRAINT chk_services_price CHECK (
        (price_type = 'fixed'
            AND price_amount_minor IS NOT NULL AND price_amount_minor >= 0
            AND min_amount_minor IS NULL AND max_amount_minor IS NULL)
        OR (price_type = 'from'
            AND price_amount_minor IS NULL
            AND min_amount_minor IS NOT NULL AND min_amount_minor >= 0
            AND max_amount_minor IS NULL)
        OR (price_type = 'range'
            AND price_amount_minor IS NULL
            AND min_amount_minor IS NOT NULL AND min_amount_minor >= 0
            AND max_amount_minor IS NOT NULL AND max_amount_minor >= min_amount_minor)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_variants (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    service_id          BIGINT UNSIGNED NOT NULL,
    name                VARCHAR(120) NOT NULL,
    duration_minutes    SMALLINT UNSIGNED NOT NULL,
    price_amount_minor  BIGINT NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_service_variants_uuid (uuid),
    UNIQUE KEY uq_service_variants_tenant_id (tenant_id, id),
    -- Lets children prove the variant belongs to the same service.
    UNIQUE KEY uq_service_variants_service_id (tenant_id, service_id, id),
    KEY idx_service_variants_service (tenant_id, service_id, is_active),
    CONSTRAINT fk_service_variants_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES services (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_service_variants_duration CHECK (duration_minutes > 0),
    CONSTRAINT chk_service_variants_price CHECK (price_amount_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_addons (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    service_id          BIGINT UNSIGNED NOT NULL,
    name                VARCHAR(120) NOT NULL,
    extra_minutes       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    price_amount_minor  BIGINT NOT NULL DEFAULT 0,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_service_addons_uuid (uuid),
    UNIQUE KEY uq_service_addons_tenant_id (tenant_id, id),
    KEY idx_service_addons_service (tenant_id, service_id, is_active),
    CONSTRAINT fk_service_addons_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES services (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_service_addons_price CHECK (price_amount_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_branches (
    tenant_id           BIGINT UNSIGNED NOT NULL,
    service_id          BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    price_amount_minor  BIGINT NULL,
    is_bookable_online  BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (tenant_id, service_id, branch_id),
    KEY idx_service_branches_branch (tenant_id, branch_id, service_id),
    CONSTRAINT fk_service_branches_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES services (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_service_branches_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_service_branches_price
        CHECK (price_amount_minor IS NULL OR price_amount_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resource_types (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_resource_types_uuid (uuid),
    UNIQUE KEY uq_resource_types_tenant_id (tenant_id, id),
    UNIQUE KEY uq_resource_types_name (tenant_id, name),
    CONSTRAINT fk_resource_types_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resources (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    resource_type_id    BIGINT UNSIGNED NOT NULL,
    name                VARCHAR(120) NOT NULL,
    capacity            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status              ENUM('active', 'maintenance', 'inactive') NOT NULL DEFAULT 'active',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_resources_uuid (uuid),
    UNIQUE KEY uq_resources_tenant_id (tenant_id, id),
    KEY idx_resources_branch (tenant_id, branch_id, status),
    CONSTRAINT fk_resources_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_resources_type
        FOREIGN KEY (tenant_id, resource_type_id)
        REFERENCES resource_types (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_resources_capacity CHECK (capacity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resource_schedules (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    resource_id     BIGINT UNSIGNED NOT NULL,
    weekday         TINYINT UNSIGNED NOT NULL,
    starts_at       TIME NOT NULL,
    ends_at         TIME NOT NULL,
    effective_from  DATE NULL,
    effective_to    DATE NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_resource_schedules_resource (tenant_id, resource_id, weekday),
    CONSTRAINT fk_resource_schedules_resource
        FOREIGN KEY (tenant_id, resource_id)
        REFERENCES resources (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_resource_schedules_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_resource_schedules_times CHECK (starts_at <> ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_resource_requirements (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    service_id          BIGINT UNSIGNED NOT NULL,
    variant_id          BIGINT UNSIGNED NULL,
    resource_type_id    BIGINT UNSIGNED NOT NULL,
    quantity            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    occupancy           ENUM('item', 'item_with_buffers') NOT NULL DEFAULT 'item',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_service_requirements_service (tenant_id, service_id),
    CONSTRAINT fk_service_requirements_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES services (tenant_id, id)
        ON DELETE CASCADE,
    -- (tenant_id, service_id, variant_id): a variant of another service is rejected.
    CONSTRAINT fk_service_requirements_variant
        FOREIGN KEY (tenant_id, service_id, variant_id)
        REFERENCES service_variants (tenant_id, service_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_service_requirements_type
        FOREIGN KEY (tenant_id, resource_type_id)
        REFERENCES resource_types (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_service_requirements_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    tenant_user_id      BIGINT UNSIGNED NULL,

    first_name          VARCHAR(100) NOT NULL,
    middle_name         VARCHAR(100) NULL,
    last_name           VARCHAR(100) NOT NULL,
    display_name        VARCHAR(150) NOT NULL,
    email               VARCHAR(254) NULL,
    phone_e164          VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    bio                 TEXT NULL,

    booking_priority    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    is_bookable_online  BOOLEAN NOT NULL DEFAULT TRUE,
    status              ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_uuid (uuid),
    UNIQUE KEY uq_staff_tenant_id (tenant_id, id),
    UNIQUE KEY uq_staff_membership (tenant_id, tenant_user_id),
    KEY idx_staff_tenant (tenant_id, status, is_bookable_online),
    CONSTRAINT fk_staff_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_staff_membership
        FOREIGN KEY (tenant_id, tenant_user_id)
        REFERENCES tenant_users (tenant_id, id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_branches (
    tenant_id   BIGINT UNSIGNED NOT NULL,
    staff_id    BIGINT UNSIGNED NOT NULL,
    branch_id   BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (tenant_id, staff_id, branch_id),
    KEY idx_staff_branches_branch (tenant_id, branch_id, staff_id),
    CONSTRAINT fk_staff_branches_staff
        FOREIGN KEY (tenant_id, staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_staff_branches_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_services (
    tenant_id           BIGINT UNSIGNED NOT NULL,
    staff_id            BIGINT UNSIGNED NOT NULL,
    service_id          BIGINT UNSIGNED NOT NULL,
    price_amount_minor  BIGINT NULL,
    duration_minutes    SMALLINT UNSIGNED NULL,
    is_bookable_online  BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (tenant_id, staff_id, service_id),
    KEY idx_staff_services_service (tenant_id, service_id, staff_id),
    CONSTRAINT fk_staff_services_staff
        FOREIGN KEY (tenant_id, staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_staff_services_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES services (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_staff_services_price
        CHECK (price_amount_minor IS NULL OR price_amount_minor >= 0),
    CONSTRAINT chk_staff_services_duration
        CHECK (duration_minutes IS NULL OR duration_minutes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_schedules (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    staff_id        BIGINT UNSIGNED NOT NULL,
    branch_id       BIGINT UNSIGNED NOT NULL,
    weekday         TINYINT UNSIGNED NOT NULL,
    starts_at       TIME NOT NULL,
    ends_at         TIME NOT NULL,
    effective_from  DATE NULL,
    effective_to    DATE NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_staff_schedules_staff (tenant_id, staff_id, weekday),
    KEY idx_staff_schedules_branch (tenant_id, branch_id, weekday),
    -- A shift can only exist at a branch the staff member is assigned to.
    -- Removing the assignment removes those shifts; StaffService must first
    -- check future appointments (booking-engine.md §9.6).
    CONSTRAINT fk_staff_schedules_assignment
        FOREIGN KEY (tenant_id, staff_id, branch_id)
        REFERENCES staff_branches (tenant_id, staff_id, branch_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_staff_schedules_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_staff_schedules_times CHECK (starts_at <> ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_breaks (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    staff_id        BIGINT UNSIGNED NOT NULL,
    weekday         TINYINT UNSIGNED NOT NULL,
    starts_at       TIME NOT NULL,
    ends_at         TIME NOT NULL,
    effective_from  DATE NULL,
    effective_to    DATE NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_staff_breaks_staff (tenant_id, staff_id, weekday),
    CONSTRAINT fk_staff_breaks_staff
        FOREIGN KEY (tenant_id, staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_staff_breaks_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_staff_breaks_times CHECK (starts_at <> ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leave types differ per business (study leave, maternity, training...), so
-- they are a tenant-scoped lookup rather than an ENUM (D-023). Defaults are
-- copied in at tenant creation, like role templates.
CREATE TABLE staff_leave_types (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name            VARCHAR(80) NOT NULL,
    is_paid         BOOLEAN NOT NULL DEFAULT TRUE,
    requires_approval BOOLEAN NOT NULL DEFAULT TRUE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_leave_types_tenant_id (tenant_id, id),
    UNIQUE KEY uq_staff_leave_types_code (tenant_id, code),
    CONSTRAINT fk_staff_leave_types_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_leave (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    staff_id                BIGINT UNSIGNED NOT NULL,
    starts_at               DATETIME NOT NULL,
    ends_at                 DATETIME NOT NULL,
    leave_type_id           BIGINT UNSIGNED NOT NULL,
    status                  ENUM('requested', 'approved', 'rejected', 'cancelled')
                                NOT NULL DEFAULT 'requested',
    reason                  VARCHAR(255) NULL,
    requested_by_user_id    BIGINT UNSIGNED NULL,
    approved_by_user_id     BIGINT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_staff_leave_window (tenant_id, staff_id, status, starts_at, ends_at),
    CONSTRAINT fk_staff_leave_staff
        FOREIGN KEY (tenant_id, staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_staff_leave_type
        FOREIGN KEY (tenant_id, leave_type_id)
        REFERENCES staff_leave_types (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_staff_leave_window CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedule_overrides (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NULL,
    staff_id            BIGINT UNSIGNED NULL,
    resource_id         BIGINT UNSIGNED NULL,

    local_date          DATE NOT NULL,
    is_closed           BOOLEAN NOT NULL DEFAULT FALSE,
    opens_at            TIME NULL,
    closes_at           TIME NULL,
    reason              VARCHAR(255) NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_schedule_overrides_branch (tenant_id, branch_id, local_date),
    KEY idx_schedule_overrides_staff (tenant_id, staff_id, local_date),
    KEY idx_schedule_overrides_resource (tenant_id, resource_id, local_date),
    CONSTRAINT fk_schedule_overrides_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_schedule_overrides_staff
        FOREIGN KEY (tenant_id, staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_schedule_overrides_resource
        FOREIGN KEY (tenant_id, resource_id)
        REFERENCES resources (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_schedule_overrides_scope CHECK (
        (branch_id IS NOT NULL) + (staff_id IS NOT NULL) + (resource_id IS NOT NULL) = 1
    ),
    CONSTRAINT chk_schedule_overrides_hours CHECK (
        (is_closed = 1 AND opens_at IS NULL AND closes_at IS NULL)
        OR (is_closed = 0 AND opens_at IS NOT NULL AND closes_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blocked_periods (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NULL,
    staff_id            BIGINT UNSIGNED NULL,
    resource_id         BIGINT UNSIGNED NULL,

    starts_at           DATETIME NOT NULL,
    ends_at             DATETIME NOT NULL,
    reason              VARCHAR(255) NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_blocked_periods_branch (tenant_id, branch_id, starts_at, ends_at),
    KEY idx_blocked_periods_staff (tenant_id, staff_id, starts_at, ends_at),
    KEY idx_blocked_periods_resource (tenant_id, resource_id, starts_at, ends_at),
    CONSTRAINT fk_blocked_periods_branch
        FOREIGN KEY (tenant_id, branch_id)
        REFERENCES branches (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_blocked_periods_staff
        FOREIGN KEY (tenant_id, staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_blocked_periods_resource
        FOREIGN KEY (tenant_id, resource_id)
        REFERENCES resources (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT chk_blocked_periods_scope CHECK (
        (branch_id IS NOT NULL) + (staff_id IS NOT NULL) + (resource_id IS NOT NULL) = 1
    ),
    CONSTRAINT chk_blocked_periods_window CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id               BIGINT UNSIGNED NOT NULL,

    first_name              VARCHAR(100) NOT NULL,
    middle_name             VARCHAR(100) NULL,
    last_name               VARCHAR(100) NOT NULL,
    phone_e164              VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    email                   VARCHAR(254) NULL,
    notes                   TEXT NULL,

    preferred_staff_id      BIGINT UNSIGNED NULL,
    preferred_service_id    BIGINT UNSIGNED NULL,

    status                  ENUM('active', 'merged', 'anonymised') NOT NULL DEFAULT 'active',
    merged_into_customer_id BIGINT UNSIGNED NULL,

    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_customers_uuid (uuid),
    UNIQUE KEY uq_customers_tenant_id (tenant_id, id),
    KEY idx_customers_tenant_phone (tenant_id, phone_e164),
    KEY idx_customers_tenant_email (tenant_id, email),
    KEY idx_customers_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_customers_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_customers_preferred_staff
        FOREIGN KEY (tenant_id, preferred_staff_id)
        REFERENCES staff (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_customers_preferred_service
        FOREIGN KEY (tenant_id, preferred_service_id)
        REFERENCES services (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_customers_merged_into
        FOREIGN KEY (tenant_id, merged_into_customer_id)
        REFERENCES customers (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_customers_merge CHECK (
        (status = 'merged' AND merged_into_customer_id IS NOT NULL)
        OR (status <> 'merged' AND merged_into_customer_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_match_keys (
    tenant_id   BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    match_type  ENUM('phone', 'email') NOT NULL,
    match_value VARCHAR(254) NOT NULL,     -- phone_e164 or lowercased email

    PRIMARY KEY (tenant_id, match_type, match_value),
    KEY idx_customer_match_keys_customer (tenant_id, customer_id),
    CONSTRAINT fk_customer_match_keys_customer
        FOREIGN KEY (tenant_id, customer_id)
        REFERENCES customers (tenant_id, id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_consents (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    channel     VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    purpose     ENUM('transactional', 'marketing') NOT NULL,
    granted     BOOLEAN NOT NULL,
    source      VARCHAR(50) NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at  DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_consents_tenant_id (tenant_id, id),
    KEY idx_customer_consents_customer (tenant_id, customer_id, channel, purpose),
    CONSTRAINT fk_customer_consents_customer
        FOREIGN KEY (tenant_id, customer_id)
        REFERENCES customers (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_customer_consents_channel
        FOREIGN KEY (channel) REFERENCES communication_channels (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

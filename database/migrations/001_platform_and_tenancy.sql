-- 001_platform_and_tenancy.sql
-- AlbaBook: platform identity, tenants, domains, status policy, settings,
-- platform administrators and audited support access.
--
-- Conventions (plan.md §13, decisions.md):
--   * InnoDB, utf8mb4, UTC instants. The connection must run SET time_zone = '+00:00'.
--   * BIGINT UNSIGNED internal keys; uuid for anything exposed in a URL or API.
--   * Tenant-owned tables carry tenant_id and UNIQUE (tenant_id, id) so children
--     can use composite tenant-aware foreign keys.
--   * Money is BIGINT minor units with an explicit ISO 4217 currency (D-006).
--   * Status/category columns that are shared, carry metadata or are extended
--     without a deploy reference lookup tables from 000 (D-023).
--   * Tenants are never hard-deleted (archived, then anonymised per D-012), so
--     every foreign key to tenants is ON DELETE RESTRICT (D-024).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE tenants (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name                VARCHAR(150) NOT NULL,
    slug                VARCHAR(100) NOT NULL,

    status              VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',

    timezone            VARCHAR(64) NOT NULL DEFAULT 'Africa/Nairobi',
    currency            CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'KES',
    default_locale      VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'en',

    vat_registered      BOOLEAN NOT NULL DEFAULT FALSE,
    tax_rate_bp         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    kra_pin             VARCHAR(20) NULL,

    settings_version    SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_uuid (uuid),
    UNIQUE KEY uq_tenants_slug (slug),
    KEY idx_tenants_status (status),

    CONSTRAINT fk_tenants_status
        FOREIGN KEY (status) REFERENCES tenant_statuses (code),
    CONSTRAINT fk_tenants_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),
    CONSTRAINT fk_tenants_locale
        FOREIGN KEY (default_locale) REFERENCES locales (code),
    CONSTRAINT chk_tenants_slug_format CHECK (
        CHAR_LENGTH(slug) BETWEEN 3 AND 60
        AND slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'
    ),
    CONSTRAINT chk_tenants_tax CHECK (
        vat_registered = 0 OR tax_rate_bp > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_status_policies (
    status      VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    allowed     BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (status, operation),
    CONSTRAINT fk_tenant_status_policies_status
        FOREIGN KEY (status) REFERENCES tenant_statuses (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_settings (
    tenant_id               BIGINT UNSIGNED NOT NULL,
    date_format             VARCHAR(16) NOT NULL DEFAULT 'd/m/Y',
    default_dashboard_view  VARCHAR(32) NOT NULL DEFAULT 'overview',
    support_write_enabled   BOOLEAN NOT NULL DEFAULT TRUE,
    onboarding_progress     JSON NULL,
    preferences             JSON NULL,
    schema_version          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tenant_settings_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_slug_history (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    BIGINT UNSIGNED NOT NULL,
    slug         VARCHAR(100) NOT NULL,
    replaced_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_slug_history_slug (slug),
    KEY idx_tenant_slug_history_tenant (tenant_id, replaced_at),
    CONSTRAINT fk_tenant_slug_history_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_domains (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    domain                  VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

    status                  ENUM(
                                'pending_verification',
                                'verified',
                                'active',
                                'failed',
                                'disabled'
                            ) NOT NULL DEFAULT 'pending_verification',

    verification_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verified_at             DATETIME NULL,
    last_checked_at         DATETIME NULL,
    tls_status              ENUM('none', 'pending', 'issued', 'failed')
                                NOT NULL DEFAULT 'none',
    is_primary              BOOLEAN NOT NULL DEFAULT FALSE,

    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_domain (domain),
    UNIQUE KEY uq_tenant_domain_pair (tenant_id, id),
    KEY idx_tenant_domains_status (status, last_checked_at),
    CONSTRAINT fk_tenant_domains_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    middle_name     VARCHAR(100) NULL,
    last_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(254) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    status          ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    mfa_enrolled_at DATETIME NULL,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_users_uuid (uuid),
    UNIQUE KEY uq_platform_users_email (email),
    KEY idx_platform_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_user_mfa_factors (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    platform_user_id    BIGINT UNSIGNED NOT NULL,
    type                ENUM('totp') NOT NULL DEFAULT 'totp',
    label               VARCHAR(100) NULL,
    secret_encrypted    VARBINARY(512) NOT NULL,
    confirmed_at        DATETIME NULL,
    last_used_step      BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_mfa_user_id (platform_user_id, id),
    KEY idx_platform_mfa_user (platform_user_id, confirmed_at),
    CONSTRAINT fk_platform_mfa_user
        FOREIGN KEY (platform_user_id) REFERENCES platform_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_user_recovery_codes (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    platform_user_id    BIGINT UNSIGNED NOT NULL,
    code_hash           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    used_at             DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_recovery_hash (code_hash),
    KEY idx_platform_recovery_user (platform_user_id, used_at),
    CONSTRAINT fk_platform_recovery_user
        FOREIGN KEY (platform_user_id) REFERENCES platform_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_sessions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform_user_id    BIGINT UNSIGNED NOT NULL,
    ip_address          VARCHAR(45) NULL,
    user_agent          VARCHAR(255) NULL,
    mfa_verified_at     DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at          DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_sessions_token (session_token_hash),
    KEY idx_platform_sessions_user (platform_user_id, revoked_at),
    CONSTRAINT fk_platform_sessions_user
        FOREIGN KEY (platform_user_id) REFERENCES platform_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_access_sessions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform_user_id    BIGINT UNSIGNED NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    reason              VARCHAR(500) NOT NULL,
    scope               ENUM('read', 'write') NOT NULL DEFAULT 'read',
    started_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME NOT NULL,
    ended_at            DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_support_sessions_uuid (uuid),
    KEY idx_support_sessions_tenant (tenant_id, started_at),
    KEY idx_support_sessions_admin (platform_user_id, started_at),
    CONSTRAINT fk_support_sessions_admin
        FOREIGN KEY (platform_user_id) REFERENCES platform_users (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_support_sessions_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_support_sessions_window CHECK (expires_at > started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

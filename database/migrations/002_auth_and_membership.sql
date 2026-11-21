-- 002_auth_and_membership.sql
-- AlbaBook: tenant user identity, memberships, roles, sessions, MFA,
-- verification/reset tokens, invitations and rate limiting.
--
-- Roles belong to a tenant and are assigned to a membership, never to a user
-- (decisions.md D-007). roles.tenant_id IS NULL holds platform role templates
-- that are copied into each tenant; the generated tenant_scope column keeps
-- template slugs unique without nullable-unique tricks.
--
-- MySQL forbids CASCADE / SET NULL on a foreign key whose column is the base
-- of a STORED generated column (roles.tenant_id -> tenant_scope). The tenant
-- FK is RESTRICT, which D-024 requires anyway.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE users (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_name          VARCHAR(100) NOT NULL,
    middle_name         VARCHAR(100) NULL,
    last_name           VARCHAR(100) NOT NULL,
    email               VARCHAR(254) NOT NULL,
    phone_e164          VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    password_hash       VARCHAR(255) NOT NULL,
    email_verified_at   DATETIME NULL,
    locale              VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'en',
    status              ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    last_login_at       DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_uuid (uuid),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_phone (phone_e164),
    KEY idx_users_status (status),
    CONSTRAINT fk_users_locale
        FOREIGN KEY (locale) REFERENCES locales (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_users (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    status              ENUM('invited', 'active', 'suspended', 'removed')
                            NOT NULL DEFAULT 'invited',
    invited_by_user_id  BIGINT UNSIGNED NULL,
    joined_at           DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_user (tenant_id, user_id),
    UNIQUE KEY uq_tenant_user_pair (tenant_id, id),
    KEY idx_tenant_users_user (user_id, status),
    CONSTRAINT fk_tenant_users_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_users_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_users_inviter
        FOREIGN KEY (invited_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id       BIGINT UNSIGNED NULL,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(100) NOT NULL,
    description     VARCHAR(255) NULL,
    is_system       BOOLEAN NOT NULL DEFAULT FALSE,

    tenant_scope    BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(tenant_id, 0)) STORED,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_uuid (uuid),
    UNIQUE KEY uq_roles_tenant_id (tenant_id, id),
    UNIQUE KEY uq_roles_scope_slug (tenant_scope, slug),
    CONSTRAINT fk_roles_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission scope is enforced declaratively (D-020): each link table carries
-- a constant scope column, pinned by CHECK, and references permissions on
-- (scope, id). A tenant role therefore cannot receive a platform permission and
-- vice versa, even through direct SQL. This replaces the earlier BEFORE
-- INSERT/UPDATE triggers, which needed DELIMITER (a mysql-client command that
-- a PDO migration runner cannot execute).
CREATE TABLE permissions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope       VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    identifier  VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name        VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_scope_identifier (scope, identifier),
    UNIQUE KEY uq_permissions_scope_id (scope, id),
    CONSTRAINT chk_permissions_scope CHECK (scope IN ('tenant', 'platform'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_user_permissions (
    platform_user_id            BIGINT UNSIGNED NOT NULL,
    permission_scope            VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'platform',
    permission_id               BIGINT UNSIGNED NOT NULL,
    granted_by_platform_user_id BIGINT UNSIGNED NULL,
    granted_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (platform_user_id, permission_id),
    KEY idx_platform_user_permissions_permission (permission_scope, permission_id),
    CONSTRAINT fk_platform_user_permissions_user
        FOREIGN KEY (platform_user_id) REFERENCES platform_users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_platform_user_permissions_permission
        FOREIGN KEY (permission_scope, permission_id) REFERENCES permissions (scope, id),
    CONSTRAINT fk_platform_user_permissions_granted_by
        FOREIGN KEY (granted_by_platform_user_id) REFERENCES platform_users (id)
        ON DELETE SET NULL,
    CONSTRAINT chk_platform_user_permissions_scope CHECK (permission_scope = 'platform')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id             BIGINT UNSIGNED NOT NULL,
    permission_scope    VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'tenant',
    permission_id       BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_permission (permission_scope, permission_id),
    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_scope, permission_id) REFERENCES permissions (scope, id),
    CONSTRAINT chk_role_permissions_scope CHECK (permission_scope = 'tenant')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership_roles (
    tenant_id       BIGINT UNSIGNED NOT NULL,
    tenant_user_id  BIGINT UNSIGNED NOT NULL,
    role_id         BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (tenant_id, tenant_user_id, role_id),
    CONSTRAINT fk_membership_roles_membership
        FOREIGN KEY (tenant_id, tenant_user_id)
        REFERENCES tenant_users (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_membership_roles_role
        FOREIGN KEY (tenant_id, role_id)
        REFERENCES roles (tenant_id, id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    active_tenant_id    BIGINT UNSIGNED NULL,
    ip_address          VARCHAR(45) NULL,
    user_agent          VARCHAR(255) NULL,
    mfa_verified_at     DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at          DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_sessions_token (session_token_hash),
    KEY idx_user_sessions_user (user_id, revoked_at),
    CONSTRAINT fk_user_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_sessions_tenant
        FOREIGN KEY (active_tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE remember_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at  DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_remember_tokens_hash (token_hash),
    KEY idx_remember_tokens_user (user_id, expires_at),
    CONSTRAINT fk_remember_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_mfa_factors (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    type            ENUM('totp') NOT NULL DEFAULT 'totp',
    label           VARCHAR(100) NULL,
    secret_encrypted VARBINARY(512) NOT NULL,
    confirmed_at    DATETIME NULL,
    last_used_step  BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_mfa_user_id (user_id, id),
    KEY idx_user_mfa_user (user_id, confirmed_at),
    CONSTRAINT fk_user_mfa_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_recovery_codes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    code_hash   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_recovery_hash (code_hash),
    KEY idx_user_recovery_user (user_id, used_at),
    CONSTRAINT fk_user_recovery_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_verification_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verification_hash (token_hash),
    KEY idx_email_verification_user (user_id, expires_at),
    CONSTRAINT fk_email_verification_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_change_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    new_email   VARCHAR(254) NOT NULL,
    token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_email_change_hash (token_hash),
    KEY idx_email_change_user (user_id, expires_at),
    CONSTRAINT fk_email_change_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_hash (token_hash),
    KEY idx_password_reset_user (user_id, expires_at),
    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_invitations (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    email               VARCHAR(254) NOT NULL,
    invited_by_user_id  BIGINT UNSIGNED NOT NULL,
    token_hash          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at          DATETIME NOT NULL,
    accepted_at         DATETIME NULL,
    revoked_at          DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_invitations_uuid (uuid),
    UNIQUE KEY uq_tenant_invitations_token (token_hash),
    UNIQUE KEY uq_tenant_invitation_pair (tenant_id, id),
    KEY idx_tenant_invitations_email (tenant_id, email, accepted_at),
    CONSTRAINT fk_tenant_invitations_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_invitations_inviter
        FOREIGN KEY (invited_by_user_id) REFERENCES users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_invitation_roles (
    tenant_id       BIGINT UNSIGNED NOT NULL,
    invitation_id   BIGINT UNSIGNED NOT NULL,
    role_id         BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (invitation_id, role_id),
    CONSTRAINT fk_invitation_roles_invitation
        FOREIGN KEY (tenant_id, invitation_id)
        REFERENCES tenant_invitations (tenant_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_invitation_roles_role
        FOREIGN KEY (tenant_id, role_id)
        REFERENCES roles (tenant_id, id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email       VARCHAR(254) NULL,
    ip_address  VARCHAR(45) NULL,
    successful  BOOLEAN NOT NULL DEFAULT FALSE,
    user_agent  VARCHAR(255) NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_login_attempts_email (email, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limit_buckets (
    bucket_key      VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start    DATETIME NOT NULL,
    count           INT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (bucket_key, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

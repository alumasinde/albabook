-- 008_integrations.sql
-- AlbaBook: tenant API credentials, webhook endpoints and deliveries, and
-- inbound integration event storage.
--
-- Private API credentials are tenant-bound, hashed and scoped (D-008). Webhook
-- signing secrets are encrypted at rest; the plaintext is shown once.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE api_credentials (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    public_id           CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    secret_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scopes              JSON NOT NULL,
    expires_at          DATETIME NULL,
    revoked_at          DATETIME NULL,
    last_used_at        DATETIME NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_api_credentials_uuid (uuid),
    UNIQUE KEY uq_api_credentials_public_id (public_id),
    UNIQUE KEY uq_api_credentials_tenant_id (tenant_id, id),
    KEY idx_api_credentials_tenant (tenant_id, revoked_at),
    CONSTRAINT fk_api_credentials_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_api_credentials_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_endpoints (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    url                 VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    secret_encrypted    VARBINARY(2048) NOT NULL,
    event_types         JSON NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    last_success_at     DATETIME NULL,
    last_failure_at     DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_endpoints_uuid (uuid),
    UNIQUE KEY uq_webhook_endpoints_tenant_id (tenant_id, id),
    KEY idx_webhook_endpoints_tenant (tenant_id, is_active),
    CONSTRAINT fk_webhook_endpoints_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_webhook_endpoints_url CHECK (url LIKE 'https://%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_deliveries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    endpoint_id     BIGINT UNSIGNED NOT NULL,
    event_type      VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload         JSON NOT NULL,
    status          ENUM('pending', 'succeeded', 'failed') NOT NULL DEFAULT 'pending',
    attempt         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    response_code   SMALLINT UNSIGNED NULL,
    response_body   VARCHAR(2000) NULL,
    error           VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at    DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_deliveries_uuid (uuid),
    KEY idx_webhook_deliveries_endpoint (tenant_id, endpoint_id, created_at),
    KEY idx_webhook_deliveries_status (status, created_at),
    CONSTRAINT fk_webhook_deliveries_endpoint
        FOREIGN KEY (tenant_id, endpoint_id)
        REFERENCES webhook_endpoints (tenant_id, id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE integration_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    provider        VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type      VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_id     VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payload         JSON NOT NULL,
    received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at    DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_integration_events_external (tenant_id, provider, external_id),
    KEY idx_integration_events_tenant (tenant_id, received_at),
    KEY idx_integration_events_unprocessed (processed_at, received_at),
    CONSTRAINT fk_integration_events_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

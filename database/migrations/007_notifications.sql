-- 007_notifications.sql
-- AlbaBook: notification providers, tenant sender accounts, per-event
-- preferences, templates, outbound queue, delivery logs and provider events.
--
-- The booking transaction never talks to a provider. It writes an outbox event;
-- a worker enqueues rows here and sends outside the transaction
-- (booking-engine.md §9, §17).

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE notification_providers (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name        VARCHAR(100) NOT NULL,
    channel     VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    config      JSON NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_providers_code (code),
    CONSTRAINT fk_notification_providers_channel
        FOREIGN KEY (channel) REFERENCES communication_channels (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_notification_accounts (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    provider_id             BIGINT UNSIGNED NOT NULL,
    sender_id               VARCHAR(64) NULL,
    credentials_encrypted   VARBINARY(2048) NULL,
    status                  ENUM('active', 'disabled') NOT NULL DEFAULT 'disabled',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_notification_accounts_tenant_id (tenant_id, id),
    UNIQUE KEY uq_tenant_notification_accounts_provider (tenant_id, provider_id),
    CONSTRAINT fk_tenant_notification_accounts_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_notification_accounts_provider
        FOREIGN KEY (provider_id) REFERENCES notification_providers (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_preferences (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    channel     VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_key   VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_enabled  BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_preferences (tenant_id, channel, event_key),
    CONSTRAINT fk_notification_preferences_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_notification_preferences_channel
        FOREIGN KEY (channel) REFERENCES communication_channels (code),
    CONSTRAINT fk_notification_preferences_event
        FOREIGN KEY (event_key) REFERENCES notification_events (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_templates (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NULL,
    channel         VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_key       VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    locale          VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'en',
    subject         VARCHAR(200) NULL,
    body            TEXT NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,

    tenant_scope    BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(tenant_id, 0)) STORED,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_templates_tenant_id (tenant_id, id),
    UNIQUE KEY uq_notification_templates_scope
        (tenant_scope, channel, event_key, locale),
    -- RESTRICT is required: tenant_id is the base of STORED tenant_scope.
    CONSTRAINT fk_notification_templates_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_notification_templates_channel
        FOREIGN KEY (channel) REFERENCES communication_channels (code),
    CONSTRAINT fk_notification_templates_event
        FOREIGN KEY (event_key) REFERENCES notification_events (code),
    CONSTRAINT fk_notification_templates_locale
        FOREIGN KEY (locale) REFERENCES locales (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_queue (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    appointment_id  BIGINT UNSIGNED NULL,
    channel         VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient       VARCHAR(254) NOT NULL,
    template_id     BIGINT UNSIGNED NULL,
    payload         JSON NOT NULL,
    status          ENUM('pending', 'processing', 'sent', 'failed', 'cancelled')
                        NOT NULL DEFAULT 'pending',
    scheduled_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error      VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at         DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_queue_uuid (uuid),
    KEY idx_notification_queue_processing (status, scheduled_at, id),
    KEY idx_notification_queue_appointment (tenant_id, appointment_id),
    CONSTRAINT fk_notification_queue_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_notification_queue_appointment
        FOREIGN KEY (tenant_id, appointment_id)
        REFERENCES appointments (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_notification_queue_template
        FOREIGN KEY (template_id) REFERENCES notification_templates (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_notification_queue_channel
        FOREIGN KEY (channel) REFERENCES communication_channels (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_logs (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    queue_id            BIGINT UNSIGNED NULL,
    channel             VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_masked    VARCHAR(254) NOT NULL,
    template_id         BIGINT UNSIGNED NULL,
    status              ENUM('sent', 'failed', 'delivered', 'undelivered') NOT NULL,
    provider_message_id VARCHAR(100) NULL,
    error_code          VARCHAR(50) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_notification_logs_tenant (tenant_id, created_at),
    KEY idx_notification_logs_provider_id (provider_message_id),
    CONSTRAINT fk_notification_logs_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_notification_logs_queue
        FOREIGN KEY (queue_id) REFERENCES notification_queue (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_notification_logs_template
        FOREIGN KEY (template_id) REFERENCES notification_templates (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_notification_logs_channel
        FOREIGN KEY (channel) REFERENCES communication_channels (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_delivery_events (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    notification_log_id BIGINT UNSIGNED NOT NULL,
    provider            VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_event_id   VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status              ENUM('sent', 'delivered', 'undelivered', 'failed') NOT NULL,
    payload             JSON NOT NULL,
    occurred_at         DATETIME NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_delivery_event (provider, provider_event_id),
    KEY idx_notification_delivery_log (tenant_id, notification_log_id),
    CONSTRAINT fk_notification_delivery_log
        FOREIGN KEY (notification_log_id) REFERENCES notification_logs (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

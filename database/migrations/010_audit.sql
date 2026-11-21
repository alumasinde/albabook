-- 010_audit.sql
-- AlbaBook: append-only audit log.
--
-- Actor types cover tenant users, platform admins, support sessions, API
-- credentials, customers and the system. before_values/after_values are masked
-- for personal data before insertion (D-012); the application never writes raw
-- phone numbers, emails or secrets here.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NULL,
    actor_type      ENUM(
                        'tenant_user',
                        'platform_user',
                        'support_session',
                        'api_credential',
                        'system',
                        'customer'
                    ) NULL,
    actor_id        BIGINT UNSIGNED NULL,
    action          VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_type     VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    target_id       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    before_values   JSON NULL,
    after_values    JSON NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(255) NULL,
    request_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_audit_logs_tenant (tenant_id, created_at),
    KEY idx_audit_logs_actor (actor_type, actor_id, created_at),
    KEY idx_audit_logs_target (target_type, target_id, created_at),
    KEY idx_audit_logs_action (action, created_at),
    CONSTRAINT fk_audit_logs_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_audit_logs_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

CREATE TRIGGER trg_audit_logs_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

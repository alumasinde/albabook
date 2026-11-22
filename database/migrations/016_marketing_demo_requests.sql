-- Demo requests from the public marketing site (albabook.co.ke/contact).
-- Not tenant data: a lead may never become a tenant. Platform admins work
-- the list from the admin console (/leads).
--
-- ip_hash is SHA-256 of the client IP (rate limiting / abuse review only,
-- never the raw address), matching how login attempts are keyed.

CREATE TABLE demo_requests (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    full_name       VARCHAR(150) NOT NULL,
    business_name   VARCHAR(150) NOT NULL,
    email           VARCHAR(254) NOT NULL,
    phone           VARCHAR(32) NULL,
    business_type   VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    branch_count    VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message         VARCHAR(2000) NULL,
    status          ENUM('new', 'contacted', 'closed') NOT NULL DEFAULT 'new',
    ip_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_demo_requests_uuid (uuid),
    KEY idx_demo_requests_status (status, created_at),
    CONSTRAINT chk_demo_requests_email CHECK (email LIKE '%_@_%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

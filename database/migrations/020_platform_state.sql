-- Platform ops state: single-row-per-key store for signals produced outside
-- web requests (cron scheduler heartbeat, backup timestamps). Kept in the
-- database rather than storage/ so host-cron writers and containerised web
-- processes share one source of truth, and so the rows are themselves
-- covered by the database backup.

CREATE TABLE platform_state (
    name        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    value       VARCHAR(1024) NOT NULL,
    updated_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

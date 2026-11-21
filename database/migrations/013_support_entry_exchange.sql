-- Worker claim states: a claimed row must be distinguishable from a fresh
-- one so two webhook workers cannot double-send the same delivery.
ALTER TABLE webhook_deliveries
    MODIFY COLUMN status ENUM('pending', 'processing', 'succeeded', 'failed')
        NOT NULL DEFAULT 'pending';

-- Support entry exchange codes (audit fix): the admin's entry link must not
-- carry a reusable credential. A single-use, short-lived code is stored here
-- and exchanged by POST for a distinct support cookie token.
ALTER TABLE support_access_sessions
    ADD COLUMN entry_code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER ended_at,
    ADD COLUMN entry_code_expires_at DATETIME NULL AFTER entry_code_hash;

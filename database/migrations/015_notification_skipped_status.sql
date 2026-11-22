-- "skipped" lets the dispatcher park a notification that exceeds the
-- tenant's monthly channel cap without burning retry attempts.
ALTER TABLE notification_queue
    MODIFY COLUMN status ENUM('pending', 'processing', 'sent', 'failed', 'cancelled', 'skipped') NOT NULL DEFAULT 'pending';

-- M2: structured cancellation reason code alongside the free-text
-- cancellation_reason. Powers the cancellations-by-reason report; free
-- text stays optional detail on appointment_events.reason.

ALTER TABLE appointments
    ADD COLUMN cancellation_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL
        AFTER cancellation_reason;

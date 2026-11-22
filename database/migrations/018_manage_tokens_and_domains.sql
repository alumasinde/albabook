-- Booking self-service foundations.
--
-- appointments.manage_token_hash: capability token (SHA-256) that unlocks the
--   public manage-booking surface (booking-engine.md §18, decisions.md D-008).
--   The plaintext is only ever shown in the confirmation payload/link.
-- appointments.idempotency_key: client-supplied key (Idempotency-Key header or
--   body field) giving booking creation exactly-once semantics — a retried
--   request returns the same appointment instead of double-booking.
--
-- (Custom domains: tenant_domains already exists since 001 — this migration
-- only adds the booking columns.)

ALTER TABLE appointments
    ADD COLUMN manage_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER reference,
    ADD COLUMN idempotency_key   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER notes,
    ADD UNIQUE KEY uq_appointments_tenant_idempotency (tenant_id, idempotency_key),
    ADD KEY idx_appointments_manage_token (manage_token_hash);

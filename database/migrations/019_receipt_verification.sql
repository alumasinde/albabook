-- Public receipt verification (albabook.co.ke/verify/{reference}-{signature}).
-- References are unique per tenant only, so the verify page looks a receipt
-- up by reference across tenants and picks the row whose signature matches.
-- The existing unique key leads with tenant_id, so it cannot serve that
-- lookup; without this index every verification would scan appointments.
ALTER TABLE appointments
    ADD KEY idx_appointments_reference (reference);

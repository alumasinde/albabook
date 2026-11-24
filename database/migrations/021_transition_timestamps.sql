-- Per-transition timestamps on appointments. appointment_events already
-- records when each status was reached (with actor + reason), but wait-time,
-- actual-duration and punctuality reports need them as columns:
--   wait_minutes      = in_service_at - checked_in_at
--   actual_duration   = completed_at  - in_service_at
--   punctuality       = checked_in_at vs start_at
-- Backfilled from the earliest matching event per (appointment, status).

ALTER TABLE appointments
    ADD COLUMN confirmed_at   DATETIME(6) NULL AFTER cancelled_at,
    ADD COLUMN checked_in_at  DATETIME(6) NULL AFTER confirmed_at,
    ADD COLUMN in_service_at  DATETIME(6) NULL AFTER checked_in_at,
    ADD COLUMN completed_at   DATETIME(6) NULL AFTER in_service_at,
    ADD COLUMN no_show_at     DATETIME(6) NULL AFTER completed_at,
    ADD COLUMN rejected_at    DATETIME(6) NULL AFTER no_show_at,
    ADD COLUMN expired_at     DATETIME(6) NULL AFTER rejected_at;

UPDATE appointments a
JOIN (
    SELECT tenant_id, appointment_id, to_status, MIN(created_at) AS first_at
      FROM appointment_events
     WHERE to_status IN ('confirmed','checked_in','in_service','completed','no_show','rejected','expired')
     GROUP BY tenant_id, appointment_id, to_status
) e ON e.tenant_id = a.tenant_id AND e.appointment_id = a.id
SET a.confirmed_at  = IF(e.to_status = 'confirmed',  e.first_at, a.confirmed_at),
    a.checked_in_at = IF(e.to_status = 'checked_in', e.first_at, a.checked_in_at),
    a.in_service_at = IF(e.to_status = 'in_service', e.first_at, a.in_service_at),
    a.completed_at  = IF(e.to_status = 'completed',  e.first_at, a.completed_at),
    a.no_show_at    = IF(e.to_status = 'no_show',    e.first_at, a.no_show_at),
    a.rejected_at   = IF(e.to_status = 'rejected',   e.first_at, a.rejected_at),
    a.expired_at    = IF(e.to_status = 'expired',    e.first_at, a.expired_at);

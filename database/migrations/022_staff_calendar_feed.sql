-- Per-staff .ics calendar feed (like Google/Outlook "subscribe to calendar").
-- The feed URL carries an unguessable token; only its SHA-256 is stored so a
-- database leak does not expose live feed links (same posture as
-- manage_token on appointments). NULL = feed not yet enabled for that member.

ALTER TABLE staff
    ADD COLUMN calendar_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER tenant_user_id,
    ADD UNIQUE KEY uq_staff_calendar_token (calendar_token_hash);

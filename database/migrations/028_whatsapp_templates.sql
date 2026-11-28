-- WhatsApp templates and sender modes.
--
-- Business-initiated WhatsApp messages (confirmations, reminders) must use a
-- template pre-approved by Meta whenever the customer has not messaged the
-- number in the last 24 hours — i.e. almost always. Each WhatsApp template
-- row now carries the provider template name, its language code and the
-- ordered payload keys that fill {{1}}, {{2}}, ….
--
-- Sender modes (tenant_settings.preferences.whatsapp.sender):
--   platform  AlbaBook's verified number sends, using the platform
--             templates seeded below (tenant_id NULL). Zero setup for
--             businesses; every message names the business in {{1}}.
--   business  The business's own WhatsApp Business number sends, using
--             template names the business maps in Settings (tenant rows).
--             Plan feature whatsapp_own_number.

ALTER TABLE notification_templates
    ADD COLUMN wa_template_name VARCHAR(512) NULL AFTER body,
    ADD COLUMN wa_language VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER wa_template_name,
    ADD COLUMN wa_params JSON NULL AFTER wa_language;

INSERT IGNORE INTO features (code, name, value_type, unit, is_metered, sort_position) VALUES
    ('whatsapp_own_number', 'WhatsApp from your own number', 'boolean', NULL, FALSE, 55);

-- Platform templates. Submit these exact bodies to Meta (category UTILITY,
-- language English) under the platform WhatsApp Business account, with the
-- names below. {{n}} are WhatsApp's positional variables.
INSERT IGNORE INTO notification_templates
    (tenant_id, channel, event_key, locale, subject, body, wa_template_name, wa_language, wa_params, is_active)
VALUES
    (NULL, 'whatsapp', 'booking_received', 'en', NULL,
     '{business_name}: we received your booking {reference} for {start_at}. You will get a confirmation shortly.',
     'albabook_booking_received', 'en', JSON_ARRAY('business_name', 'reference', 'start_at'), TRUE),
    (NULL, 'whatsapp', 'booking_confirmed', 'en', NULL,
     '{business_name}: your booking {reference} is confirmed for {start_at}.',
     'albabook_booking_confirmed', 'en', JSON_ARRAY('business_name', 'reference', 'start_at'), TRUE),
    (NULL, 'whatsapp', 'booking_rejected', 'en', NULL,
     '{business_name}: your booking {reference} could not be accommodated. Please pick another time.',
     'albabook_booking_rejected', 'en', JSON_ARRAY('business_name', 'reference'), TRUE),
    (NULL, 'whatsapp', 'appointment_reminder_24h', 'en', NULL,
     '{business_name}: reminder, your appointment {reference} is tomorrow at {start_at}.',
     'albabook_reminder_24h', 'en', JSON_ARRAY('business_name', 'reference', 'start_at'), TRUE),
    (NULL, 'whatsapp', 'appointment_reminder_2h', 'en', NULL,
     '{business_name}: reminder, your appointment {reference} starts soon ({start_at}).',
     'albabook_reminder_2h', 'en', JSON_ARRAY('business_name', 'reference', 'start_at'), TRUE),
    (NULL, 'whatsapp', 'appointment_rescheduled', 'en', NULL,
     '{business_name}: your appointment {reference} was moved to {start_at}.',
     'albabook_appointment_rescheduled', 'en', JSON_ARRAY('business_name', 'reference', 'start_at'), TRUE),
    (NULL, 'whatsapp', 'appointment_cancelled', 'en', NULL,
     '{business_name}: your appointment {reference} has been cancelled.',
     'albabook_appointment_cancelled', 'en', JSON_ARRAY('business_name', 'reference'), TRUE),
    (NULL, 'whatsapp', 'appointment_expired', 'en', NULL,
     '{business_name}: your booking {reference} expired before it could be confirmed.',
     'albabook_appointment_expired', 'en', JSON_ARRAY('business_name', 'reference'), TRUE),
    (NULL, 'whatsapp', 'appointment_completed', 'en', NULL,
     'Thank you for visiting {business_name}! Your appointment {reference} is complete.',
     'albabook_appointment_completed', 'en', JSON_ARRAY('business_name', 'reference'), TRUE),
    (NULL, 'whatsapp', 'payment_update', 'en', NULL,
     '{business_name}: payment update for appointment {reference}: paid {paid_amount} of {total_amount}.',
     'albabook_payment_update', 'en', JSON_ARRAY('business_name', 'reference', 'paid_amount', 'total_amount'), TRUE),
    (NULL, 'whatsapp', 'manage_link', 'en', NULL,
     '{business_name}: manage your booking {reference} here: {manage_url}',
     'albabook_manage_link', 'en', JSON_ARRAY('business_name', 'reference', 'manage_url'), TRUE);

-- 000_reference_data.sql
-- AlbaBook: migration bookkeeping and platform-wide lookup (reference) tables.
--
-- Lookup tables follow decisions.md D-023:
--   * Primary key is a stable ASCII `code` (not an auto-increment id), so
--     referencing columns stay readable (status = 'confirmed') and hot queries
--     need no join.
--   * `name` is the English default label only. The UI translates via
--     lookup.<table>.<code> keys (D-017).
--   * Rows are seeded here because the schema depends on them. They are
--     reference data, not demo data, and never live in database/seeders.
--   * Codes are never renamed or deleted once used; retire a value with
--     is_active = FALSE.
--   * Where code branches on a value, a PHP backed enum mirrors the codes and a
--     test asserts the enum cases equal the seeded rows.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE schema_migrations (
    version     VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Currencies (ISO 4217). `minor_unit_exponent` drives CurrencyFormatter and
-- every *_minor conversion (D-006).
-- ---------------------------------------------------------------------------
CREATE TABLE currencies (
    code                CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name                VARCHAR(60) NOT NULL,
    symbol              VARCHAR(8) NOT NULL,
    minor_unit_exponent TINYINT UNSIGNED NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (code),
    CONSTRAINT chk_currencies_code CHECK (code REGEXP '^[A-Z]{3}$'),
    CONSTRAINT chk_currencies_exponent CHECK (minor_unit_exponent <= 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO currencies (code, name, symbol, minor_unit_exponent, is_active, sort_position) VALUES
    ('KES', 'Kenyan shilling',     'KSh', 2, TRUE,  10),
    ('UGX', 'Ugandan shilling',    'USh', 0, FALSE, 20),
    ('TZS', 'Tanzanian shilling',  'TSh', 2, FALSE, 30),
    ('RWF', 'Rwandan franc',       'FRw', 0, FALSE, 40),
    ('USD', 'US dollar',           '$',   2, FALSE, 50);

-- ---------------------------------------------------------------------------
-- Locales (D-017). English everywhere; Swahili on customer-facing surfaces.
-- ---------------------------------------------------------------------------
CREATE TABLE locales (
    code                VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name                VARCHAR(60) NOT NULL,
    native_name         VARCHAR(60) NOT NULL,
    is_customer_facing  BOOLEAN NOT NULL DEFAULT FALSE,
    is_dashboard        BOOLEAN NOT NULL DEFAULT FALSE,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO locales (code, name, native_name, is_customer_facing, is_dashboard, sort_position) VALUES
    ('en', 'English', 'English',   TRUE, TRUE,  10),
    ('sw', 'Swahili', 'Kiswahili', TRUE, FALSE, 20);

-- ---------------------------------------------------------------------------
-- Tenant lifecycle statuses (D-010). The set is a code-level state machine;
-- this table gives it referential integrity, labels and metadata. What each
-- status allows lives in tenant_status_policies (001).
-- ---------------------------------------------------------------------------
CREATE TABLE tenant_statuses (
    code            VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name            VARCHAR(60) NOT NULL,
    is_terminal     BOOLEAN NOT NULL DEFAULT FALSE,
    sort_position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_statuses (code, name, is_terminal, sort_position) VALUES
    ('pending',   'Pending',   FALSE, 10),
    ('trial',     'Trial',     FALSE, 20),
    ('active',    'Active',    FALSE, 30),
    ('suspended', 'Suspended', FALSE, 40),
    ('cancelled', 'Cancelled', FALSE, 50),
    ('archived',  'Archived',  TRUE,  60);

-- ---------------------------------------------------------------------------
-- Appointment statuses (booking-engine.md §10.1). `blocks_time` is the
-- documented source of truth for which statuses occupy staff/resources; the
-- engine's PHP enum must agree (asserted by a test).
-- ---------------------------------------------------------------------------
CREATE TABLE appointment_statuses (
    code            VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name            VARCHAR(60) NOT NULL,
    blocks_time     BOOLEAN NOT NULL,
    is_terminal     BOOLEAN NOT NULL DEFAULT FALSE,
    badge_tone      ENUM('neutral', 'info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'neutral',
    sort_position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO appointment_statuses (code, name, blocks_time, is_terminal, badge_tone, sort_position) VALUES
    ('pending',    'Pending',    TRUE,  FALSE, 'warning', 10),
    ('confirmed',  'Confirmed',  TRUE,  FALSE, 'info',    20),
    ('checked_in', 'Checked in', TRUE,  FALSE, 'info',    30),
    ('completed',  'Completed',  FALSE, TRUE,  'success', 40),
    ('no_show',    'No-show',    FALSE, TRUE,  'danger',  50),
    ('cancelled',  'Cancelled',  FALSE, TRUE,  'neutral', 60),
    ('rejected',   'Rejected',   FALSE, TRUE,  'neutral', 70),
    ('expired',    'Expired',    FALSE, TRUE,  'neutral', 80);

-- ---------------------------------------------------------------------------
-- Communication channels. Shared by consents, notification preferences,
-- templates, queue, logs and providers.
-- ---------------------------------------------------------------------------
CREATE TABLE communication_channels (
    code                    VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name                    VARCHAR(60) NOT NULL,
    is_notification_channel BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position           SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO communication_channels (code, name, is_notification_channel, sort_position) VALUES
    ('whatsapp', 'WhatsApp',   TRUE,  10),
    ('sms',      'SMS',        TRUE,  20),
    ('email',    'Email',      TRUE,  30),
    ('phone',    'Phone call', FALSE, 40);

-- ---------------------------------------------------------------------------
-- Notification events (plan.md §11.1, booking-engine.md §17). Replaces the
-- free-text event_key / kind columns, which allowed silent typos.
-- ---------------------------------------------------------------------------
CREATE TABLE notification_events (
    code                VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name                VARCHAR(100) NOT NULL,
    audience            ENUM('customer', 'business') NOT NULL DEFAULT 'customer',
    is_transactional    BOOLEAN NOT NULL DEFAULT TRUE,      -- FALSE requires marketing consent
    is_reminder         BOOLEAN NOT NULL DEFAULT FALSE,     -- valid as scheduled_notifications.kind
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_events (code, name, audience, is_transactional, is_reminder, sort_position) VALUES
    ('booking_received',          'Booking received',            'customer', TRUE, FALSE, 10),
    ('booking_confirmed',         'Booking confirmed',           'customer', TRUE, FALSE, 20),
    ('booking_rejected',          'Booking rejected',            'customer', TRUE, FALSE, 30),
    ('appointment_reminder_24h',  '24-hour reminder',            'customer', TRUE, TRUE,  40),
    ('appointment_reminder_2h',   '2-hour reminder',             'customer', TRUE, TRUE,  50),
    ('appointment_rescheduled',   'Appointment rescheduled',     'customer', TRUE, FALSE, 60),
    ('appointment_cancelled',     'Appointment cancelled',       'customer', TRUE, FALSE, 70),
    ('appointment_expired',       'Appointment expired',         'customer', TRUE, FALSE, 80),
    ('appointment_completed',     'Appointment completed',       'customer', TRUE, FALSE, 90),
    ('payment_update',            'Deposit or payment update',   'customer', TRUE, FALSE, 100),
    ('manage_link',               'Booking management link',     'customer', TRUE, FALSE, 110),
    ('verification_code',         'Verification code',           'customer', TRUE, FALSE, 120),
    ('business_new_booking',      'New booking (business)',      'business', TRUE, FALSE, 200),
    ('business_booking_cancelled','Booking cancelled (business)','business', TRUE, FALSE, 210);

-- ---------------------------------------------------------------------------
-- Payment methods. Shared by customer payments (005) and AlbaTech
-- subscription payments (009). New methods are a row, not a migration.
-- ---------------------------------------------------------------------------
CREATE TABLE payment_methods (
    code                    VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name                    VARCHAR(60) NOT NULL,
    requires_reference      BOOLEAN NOT NULL DEFAULT FALSE,   -- e.g. the M-Pesa transaction code
    is_cash                 BOOLEAN NOT NULL DEFAULT FALSE,   -- for cash-up reports
    for_customer_payments   BOOLEAN NOT NULL DEFAULT TRUE,
    for_subscriptions       BOOLEAN NOT NULL DEFAULT FALSE,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position           SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO payment_methods
    (code, name, requires_reference, is_cash, for_customer_payments, for_subscriptions, sort_position) VALUES
    ('cash',          'Cash',          FALSE, TRUE,  TRUE,  FALSE, 10),
    ('mpesa',         'M-Pesa',        TRUE,  FALSE, TRUE,  TRUE,  20),
    ('airtel_money',  'Airtel Money',  TRUE,  FALSE, TRUE,  FALSE, 30),
    ('card',          'Card',          FALSE, FALSE, TRUE,  TRUE,  40),
    ('bank_transfer', 'Bank transfer', TRUE,  FALSE, TRUE,  TRUE,  50),
    ('other',         'Other',         FALSE, FALSE, TRUE,  TRUE,  90);

-- ---------------------------------------------------------------------------
-- Payment providers (integrations that send callbacks). Shared by
-- tenant_payment_accounts, payments and payment_provider_events (005).
-- ---------------------------------------------------------------------------
CREATE TABLE payment_providers (
    code            VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name            VARCHAR(60) NOT NULL,
    payment_method  VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (code),
    CONSTRAINT fk_payment_providers_method
        FOREIGN KEY (payment_method) REFERENCES payment_methods (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO payment_providers (code, name, payment_method) VALUES
    ('mpesa_daraja', 'M-Pesa (Safaricom Daraja)', 'mpesa');

-- ---------------------------------------------------------------------------
-- Subscription features and metered usage (plan.md §15). Replaces free-text
-- feature_key / metric_key columns: a typo used to disable a feature silently.
-- ---------------------------------------------------------------------------
CREATE TABLE features (
    code            VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name            VARCHAR(100) NOT NULL,
    value_type      ENUM('boolean', 'limit') NOT NULL,
    unit            VARCHAR(30) NULL,              -- e.g. 'staff', 'bookings/month'
    is_metered      BOOLEAN NOT NULL DEFAULT FALSE, -- valid as usage_records.metric
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO features (code, name, value_type, unit, is_metered, sort_position) VALUES
    ('max_staff',             'Staff members',            'limit',   'staff',          FALSE, 10),
    ('max_branches',          'Branches',                 'limit',   'branches',       FALSE, 20),
    ('monthly_bookings',      'Bookings per month',       'limit',   'bookings/month', TRUE,  30),
    ('sms_messages',          'SMS messages per month',   'limit',   'segments/month', TRUE,  40),
    ('whatsapp_messages',     'WhatsApp messages / month','limit',   'messages/month', TRUE,  50),
    ('custom_booking_domain', 'Custom booking domain',    'boolean', NULL,             FALSE, 60),
    ('advanced_themes',       'Advanced themes',          'boolean', NULL,             FALSE, 70),
    ('white_label',           'Hide "Powered by AlbaBook"','boolean', NULL,            FALSE, 80),
    ('api_access',            'API access',               'boolean', NULL,             FALSE, 90),
    ('advanced_reports',      'Advanced reports',         'boolean', NULL,             FALSE, 100),
    ('sms_custom_sender_id',  'Custom SMS sender ID',     'boolean', NULL,             FALSE, 110),
    ('gallery_images',        'Gallery images',           'limit',   'images',         FALSE, 120);

-- ---------------------------------------------------------------------------
-- Booking page section types (plan.md §8.4). The editor and the published
-- snapshot validator only accept these codes.
-- ---------------------------------------------------------------------------
CREATE TABLE booking_page_section_types (
    code                VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name                VARCHAR(60) NOT NULL,
    default_position    SMALLINT UNSIGNED NOT NULL,
    enabled_by_default  BOOLEAN NOT NULL DEFAULT TRUE,
    feature_code        VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,  -- entitlement needed, if any
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (code),
    CONSTRAINT fk_section_types_feature
        FOREIGN KEY (feature_code) REFERENCES features (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO booking_page_section_types (code, name, default_position, enabled_by_default) VALUES
    ('hero',               'Hero',               10,  TRUE),
    ('about',              'About',              20,  TRUE),
    ('service_categories', 'Service categories', 30,  FALSE),
    ('services',           'Services',           40,  TRUE),
    ('staff',              'Staff',              50,  TRUE),
    ('gallery',            'Gallery',            60,  FALSE),
    ('testimonials',       'Testimonials',       70,  FALSE),
    ('locations',          'Locations',          80,  TRUE),
    ('opening_hours',      'Opening hours',      90,  TRUE),
    ('contact',            'Contact',            100, TRUE),
    ('faqs',               'FAQs',               110, FALSE),
    ('social',             'Social links',       120, FALSE);

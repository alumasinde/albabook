-- 006_booking_pages.sql
-- AlbaBook: tenant assets, branding, booking-page settings, sections, structured
-- content and published version snapshots.
--
-- Tenant content is structured plain text, never HTML (D-002). The public page
-- renders only the active published version; drafts live in the editable tables
-- until published.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE tenant_assets (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    kind                ENUM('logo', 'favicon', 'gallery', 'og_image') NOT NULL,
    storage_path        VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name       VARCHAR(255) NULL,
    mime_type           VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    byte_size           INT UNSIGNED NOT NULL,
    width               SMALLINT UNSIGNED NULL,
    height              SMALLINT UNSIGNED NULL,
    alt_text            VARCHAR(255) NULL,
    sort_position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_assets_uuid (uuid),
    UNIQUE KEY uq_tenant_assets_tenant_id (tenant_id, id),
    KEY idx_tenant_assets_kind (tenant_id, kind, sort_position),
    CONSTRAINT fk_tenant_assets_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tenant_assets_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL,
    CONSTRAINT chk_tenant_assets_size CHECK (byte_size > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_branding (
    tenant_id           BIGINT UNSIGNED NOT NULL,
    logo_asset_id       BIGINT UNSIGNED NULL,
    favicon_asset_id    BIGINT UNSIGNED NULL,
    primary_color       CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '#6D5CE8',
    secondary_color     CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '#171A2C',
    accent_color        CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '#16A085',
    background_color    CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '#FFFFFF',
    text_color          CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '#202124',
    appearance          ENUM('light', 'dark') NOT NULL DEFAULT 'light',
    font_family         VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'inter',
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tenant_branding_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tenant_branding_logo
        FOREIGN KEY (tenant_id, logo_asset_id)
        REFERENCES tenant_assets (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_branding_favicon
        FOREIGN KEY (tenant_id, favicon_asset_id)
        REFERENCES tenant_assets (tenant_id, id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_page_settings (
    tenant_id                   BIGINT UNSIGNED NOT NULL,
    is_enabled                  BOOLEAN NOT NULL DEFAULT FALSE,

    seo_title                   VARCHAR(70) NULL,
    seo_description             VARCHAR(160) NULL,
    og_title                    VARCHAR(70) NULL,
    og_description              VARCHAR(160) NULL,
    og_image_asset_id           BIGINT UNSIGNED NULL,
    allow_indexing              BOOLEAN NOT NULL DEFAULT TRUE,

    booking_flow                ENUM('step_by_step', 'compact') NOT NULL DEFAULT 'step_by_step',
    staff_selection             ENUM('required', 'optional', 'hidden') NOT NULL DEFAULT 'optional',
    branch_selection            ENUM('required', 'optional', 'hidden') NOT NULL DEFAULT 'optional',
    service_presentation        ENUM('cards', 'list') NOT NULL DEFAULT 'cards',
    calendar_presentation       ENUM('standard', 'compact') NOT NULL DEFAULT 'standard',
    time_slot_presentation      ENUM('grid', 'list') NOT NULL DEFAULT 'grid',
    show_prices                 BOOLEAN NOT NULL DEFAULT TRUE,
    show_duration               BOOLEAN NOT NULL DEFAULT TRUE,
    default_locale              CHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'en',

    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_booking_page_settings_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_booking_page_settings_og_image
        FOREIGN KEY (tenant_id, og_image_asset_id)
        REFERENCES tenant_assets (tenant_id, id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_page_sections (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    section_key     ENUM(
                        'hero',
                        'service_categories',
                        'about',
                        'services',
                        'staff',
                        'gallery',
                        'testimonials',
                        'locations',
                        'opening_hours',
                        'contact',
                        'faqs',
                        'social'
                    ) NOT NULL,
    is_enabled      BOOLEAN NOT NULL DEFAULT TRUE,
    sort_position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_page_sections_key (tenant_id, section_key),
    CONSTRAINT fk_booking_page_sections_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_page_faqs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    question        VARCHAR(300) NOT NULL,
    answer          TEXT NOT NULL,
    sort_position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_page_faqs_tenant_id (tenant_id, id),
    KEY idx_booking_page_faqs_order (tenant_id, sort_position),
    CONSTRAINT fk_booking_page_faqs_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_page_testimonials (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    author_name             VARCHAR(120) NOT NULL,
    text                    TEXT NOT NULL,
    rating                  TINYINT UNSIGNED NULL,
    source                  VARCHAR(100) NULL,
    is_confirmed_genuine    BOOLEAN NOT NULL DEFAULT FALSE,
    sort_position           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_page_testimonials_tenant_id (tenant_id, id),
    KEY idx_booking_page_testimonials_order (tenant_id, sort_position),
    CONSTRAINT fk_booking_page_testimonials_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE CASCADE,
    CONSTRAINT chk_booking_page_testimonials_rating
        CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_page_versions (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    version_number          INT UNSIGNED NOT NULL,
    snapshot                JSON NOT NULL,
    status                  ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    is_active               BOOLEAN NOT NULL DEFAULT FALSE,
    published_at            DATETIME NULL,
    published_by_user_id    BIGINT UNSIGNED NULL,

    active_tenant_id        BIGINT UNSIGNED GENERATED ALWAYS AS
                                (CASE WHEN is_active THEN tenant_id ELSE NULL END) STORED,

    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_page_versions_uuid (uuid),
    UNIQUE KEY uq_booking_page_versions_tenant_id (tenant_id, id),
    UNIQUE KEY uq_booking_page_versions_number (tenant_id, version_number),
    UNIQUE KEY uq_booking_page_versions_active (tenant_id, active_tenant_id),
    CONSTRAINT fk_booking_page_versions_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_booking_page_versions_publisher
        FOREIGN KEY (published_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL,
    CONSTRAINT chk_booking_page_versions_published CHECK (
        status <> 'published' OR published_at IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

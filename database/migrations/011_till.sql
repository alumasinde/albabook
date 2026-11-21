-- 011_till.sql
-- AlbaBook: point-of-sale / till layer.
--
-- The appointment is the ticket. Booked services stay in appointment_items
-- (they occupy schedule time); items rung up at the till live in
-- appointment_sale_items and occupy nothing. Tenders stay in payments;
-- payment_status on the appointment is always derived, never edited
-- directly. checkout_full_payment_required lets a tenant allow checkout
-- with an outstanding balance (pay-later policy, D-0xx).

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- In-service sits between check-in and completion: reception marks the
-- client arrived (checked_in), the service provider starts work
-- (in_service), checkout completes the visit. Still blocks the calendar.
INSERT IGNORE INTO appointment_statuses (code, name, blocks_time, is_terminal, badge_tone, sort_position) VALUES
    ('in_service', 'In service', TRUE, FALSE, 'info', 35);

-- Per-tenant checkout policy: require full payment before completing, or
-- allow completing with a balance outstanding.
ALTER TABLE tenant_settings
    ADD COLUMN IF NOT EXISTS checkout_full_payment_required BOOLEAN NOT NULL DEFAULT TRUE AFTER default_dashboard_view;

-- Sellable retail items rung up at the till. NULL stock_quantity means
-- the product is not stock-tracked.
CREATE TABLE IF NOT EXISTS products (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,
    sku             VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
    price_minor     BIGINT NOT NULL DEFAULT 0,
    tax_rate_bp     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    currency        CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'KES',
    stock_quantity  INT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_products_uuid (uuid),
    UNIQUE KEY uq_products_tenant_id (tenant_id, id),
    UNIQUE KEY uq_products_sku (tenant_id, sku),
    KEY idx_products_tenant_active (tenant_id, is_active),
    CONSTRAINT fk_products_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_products_currency
        FOREIGN KEY (currency) REFERENCES currencies (code),
    CONSTRAINT chk_products_price CHECK (price_minor >= 0),
    CONSTRAINT chk_products_tax CHECK (tax_rate_bp BETWEEN 0 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Till line items on an appointment ticket. Booked items live in
-- appointment_items; everything added after check-in lands here so the
-- schedule is never mutated mid-visit. total_minor is signed: discount
-- lines carry a negative total.
CREATE TABLE IF NOT EXISTS appointment_sale_items (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    appointment_id      BIGINT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    kind                ENUM('service', 'product', 'discount', 'tip') NOT NULL,
    service_id          BIGINT UNSIGNED NULL,
    product_id          BIGINT UNSIGNED NULL,
    label               VARCHAR(150) NOT NULL,          -- name/reason snapshot
    quantity            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_amount_minor   BIGINT NOT NULL DEFAULT 0,      -- unsigned price; sign lives on total_minor
    rate_bp             SMALLINT UNSIGNED NULL,          -- percent discounts, for display/recalc
    tax_rate_bp         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tax_amount_minor    BIGINT NOT NULL DEFAULT 0,
    total_minor         BIGINT NOT NULL,                -- signed; negative for discounts
    added_by_user_id    BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_sale_items_tenant_id (tenant_id, id),
    UNIQUE KEY uq_sale_items_line (tenant_id, appointment_id, line_no),
    KEY idx_sale_items_product (tenant_id, product_id),
    CONSTRAINT fk_sale_items_appointment
        FOREIGN KEY (tenant_id, appointment_id)
        REFERENCES appointments (tenant_id, id)
        ON DELETE RESTRICT,
    -- RESTRICT, not SET NULL: a composite FK on (tenant_id, x_id) would
    -- null tenant_id too. Sold services/products can't be deleted — the
    -- API returns *_IN_USE instead (label snapshot keeps history intact).
    CONSTRAINT fk_sale_items_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES services (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_sale_items_product
        FOREIGN KEY (tenant_id, product_id)
        REFERENCES products (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_sale_items_added_by
        FOREIGN KEY (added_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL,
    -- kind/service_id/product_id consistency is enforced by TillService:
    -- MariaDB CHECK clauses cannot reference enum columns (error 1901).
    CONSTRAINT chk_sale_items_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

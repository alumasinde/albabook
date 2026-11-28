-- KRA eTIMS (OSCU — Online Sales Control Unit) tax invoicing.
--
-- At checkout a completed ticket gets an eTIMS invoice: numbered from a
-- per-tenant counter under a row lock (gapless, sequential as KRA requires)
-- and frozen as a request snapshot, so later edits to services/prices never
-- change an issued invoice. The scheduler submits pending invoices to KRA
-- and stores the signed result (receipt number, internal data, signature)
-- that the receipt prints with KRA's verification QR.
--
-- Items must be registered with KRA (saveItem) before they appear on a sale;
-- etims_items maps services/products to their KRA item codes.

CREATE TABLE etims_counters (
    tenant_id           BIGINT UNSIGNED NOT NULL,
    next_invoice_no     INT UNSIGNED NOT NULL DEFAULT 1,
    next_item_seq       INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_etims_counters_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE etims_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    kind            ENUM('service', 'product') NOT NULL,
    ref_id          BIGINT UNSIGNED NOT NULL,
    item_cd         VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_name       VARCHAR(200) NOT NULL,
    tax_ty_cd       CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registered_at   DATETIME NULL,
    last_error      VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_etims_items_ref (tenant_id, kind, ref_id),
    UNIQUE KEY uq_etims_items_code (tenant_id, item_cd),
    CONSTRAINT fk_etims_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE etims_invoices (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    appointment_id      BIGINT UNSIGNED NOT NULL,
    invoice_no          INT UNSIGNED NOT NULL,
    status              ENUM('pending', 'submitted', 'failed') NOT NULL DEFAULT 'pending',
    attempts            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at     DATETIME NULL,
    last_error          VARCHAR(500) NULL,
    request_payload     JSON NOT NULL,
    response_payload    JSON NULL,
    -- Signed result from KRA (printed on the receipt).
    sdc_id              VARCHAR(40) NULL,
    mrc_no              VARCHAR(40) NULL,
    rcpt_no             INT UNSIGNED NULL,
    tot_rcpt_no         INT UNSIGNED NULL,
    intrl_data          VARCHAR(100) NULL,
    rcpt_sign           VARCHAR(100) NULL,
    sdc_date_time       VARCHAR(20) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at        DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_etims_invoices_uuid (uuid),
    UNIQUE KEY uq_etims_invoices_appointment (tenant_id, appointment_id),
    UNIQUE KEY uq_etims_invoices_number (tenant_id, invoice_no),
    KEY idx_etims_invoices_due (status, next_attempt_at),
    CONSTRAINT fk_etims_invoices_appointment
        FOREIGN KEY (tenant_id, appointment_id) REFERENCES appointments (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- M-Pesa STK push (Lipa na M-Pesa Online) requests.
--
-- One row per prompt sent to a customer's phone. The linked payments row
-- is created 'pending' at initiation (reserving the amount against the
-- ticket balance) and becomes 'succeeded' / 'failed' when Safaricom's
-- callback — or, if the callback is lost, an STK Query — settles it.
-- checkout_request_id is Safaricom's handle and the callback lookup key.

CREATE TABLE mpesa_stk_requests (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    branch_id               BIGINT UNSIGNED NOT NULL,
    appointment_id          BIGINT UNSIGNED NOT NULL,
    payment_id              BIGINT UNSIGNED NOT NULL,
    payment_account_id      BIGINT UNSIGNED NOT NULL,
    phone_e164              VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount_minor            BIGINT NOT NULL,
    status                  ENUM('initiated', 'pending', 'succeeded', 'failed', 'cancelled', 'timeout')
                                NOT NULL DEFAULT 'initiated',
    merchant_request_id     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    checkout_request_id     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    result_code             VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    result_desc             VARCHAR(255) NULL,
    mpesa_receipt           VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    confirmed_via           ENUM('callback', 'query') NULL,
    last_queried_at         DATETIME NULL,
    requested_by_user_id    BIGINT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    completed_at            DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_mpesa_stk_uuid (uuid),
    UNIQUE KEY uq_mpesa_stk_checkout (checkout_request_id),
    KEY idx_mpesa_stk_appointment (tenant_id, appointment_id, created_at),
    KEY idx_mpesa_stk_pending (status, created_at),
    CONSTRAINT fk_mpesa_stk_appointment
        FOREIGN KEY (tenant_id, appointment_id) REFERENCES appointments (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_mpesa_stk_payment
        FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_mpesa_stk_account
        FOREIGN KEY (tenant_id, payment_account_id) REFERENCES tenant_payment_accounts (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_mpesa_stk_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

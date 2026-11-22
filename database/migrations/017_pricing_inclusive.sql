-- Prices become tax-inclusive (eTIMS-style): the quoted unit price already
-- contains VAT and service charge; levies are extracted for reporting and
-- the receipt instead of added on top. Previously unit_amount_minor was a
-- net amount and tax_amount_minor an add-on — under the new semantics the
-- unit price IS the inclusive price, and tax_amount_minor is the VAT
-- portion contained inside one unit.
--
-- Recompute per-line VAT: net = unit / (1 + vat + sc), vat = net * vat_bp.
-- Assignments evaluate left-to-right, so tax_amount_minor sees the updated
-- tax_rate_bp below.

UPDATE appointment_items ai
JOIN tenants t ON t.id = ai.tenant_id
LEFT JOIN tenant_settings ts ON ts.tenant_id = ai.tenant_id
SET ai.tax_rate_bp = CASE WHEN ai.tax_rate_bp = 0 THEN t.tax_rate_bp ELSE ai.tax_rate_bp END,
    ai.tax_amount_minor = FLOOR(
        FLOOR(ai.unit_amount_minor * 10000 /
            (10000 + ai.tax_rate_bp
                  + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(ts.preferences, '$.service_charge_bp')) AS UNSIGNED), 0)))
        * ai.tax_rate_bp / 10000
    );

UPDATE appointment_sale_items si
LEFT JOIN tenant_settings ts ON ts.tenant_id = si.tenant_id
SET si.tax_amount_minor = FLOOR(
        FLOOR(si.unit_amount_minor * 10000 /
            (10000 + si.tax_rate_bp
                  + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(ts.preferences, '$.service_charge_bp')) AS UNSIGNED), 0)))
        * si.tax_rate_bp / 10000
    ),
    si.total_minor = CASE
        WHEN si.kind IN ('service', 'product') THEN si.unit_amount_minor * si.quantity
        ELSE si.total_minor
    END;

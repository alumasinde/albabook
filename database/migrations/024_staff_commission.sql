-- Commission % per staff member (0-100). NULL = no commission tracking for
-- that member. Powers Reports -> Sales -> Commission by staff.

ALTER TABLE staff
    ADD COLUMN commission_pct DECIMAL(5,2) NULL AFTER bio;

-- DealerFAI Make Consolidation Script
-- Merges VW (8) into Volkswagen (47) and Mercedes (1) into Mercedes-Benz (41)

SET FOREIGN_KEY_CHECKS = 0;

-- Update deals
UPDATE deals SET vehicle_make_id = 47 WHERE vehicle_make_id = 8;
UPDATE deals SET vehicle_make_id = 41 WHERE vehicle_make_id = 1;

-- Update deal_change_audit_log
UPDATE deal_change_audit_log SET vehicle_make_id = 47 WHERE vehicle_make_id = 8;
UPDATE deal_change_audit_log SET vehicle_make_id = 41 WHERE vehicle_make_id = 1;

-- Update product_vehicle_eligibility
-- Use IGNORE to avoid unique key conflicts if rules already exist for the target make
UPDATE IGNORE product_vehicle_eligibility SET make_id = 47 WHERE make_id = 8;
UPDATE IGNORE product_vehicle_eligibility SET make_id = 41 WHERE make_id = 1;
-- Clean up remaining duplicates that couldn't be merged due to UNIQUE constraint
DELETE FROM product_vehicle_eligibility WHERE make_id IN (1, 8);

-- Update product_vehicle_pricing_overrides
UPDATE IGNORE product_vehicle_pricing_overrides SET vehicle_make_id = 47 WHERE vehicle_make_id = 8;
UPDATE IGNORE product_vehicle_pricing_overrides SET vehicle_make_id = 41 WHERE vehicle_make_id = 1;
DELETE FROM product_vehicle_pricing_overrides WHERE vehicle_make_id IN (1, 8);

-- Update product_organization_overrides
UPDATE IGNORE product_organization_overrides SET vehicle_make_id = 47 WHERE vehicle_make_id = 8;
UPDATE IGNORE product_organization_overrides SET vehicle_make_id = 41 WHERE vehicle_make_id = 1;
DELETE FROM product_organization_overrides WHERE vehicle_make_id IN (1, 8);

-- Update product_pricing_overrides
UPDATE IGNORE product_pricing_overrides SET make_id = 47 WHERE make_id = 8;
UPDATE IGNORE product_pricing_overrides SET make_id = 41 WHERE make_id = 1;
DELETE FROM product_pricing_overrides WHERE make_id IN (1, 8);

-- Update accessory_fitment
UPDATE IGNORE accessory_fitment SET make_id = 47 WHERE make_id = 8;
UPDATE IGNORE accessory_fitment SET make_id = 41 WHERE make_id = 1;
DELETE FROM accessory_fitment WHERE make_id IN (1, 8);

-- Update vehicle_models (existing ones)
UPDATE IGNORE vehicle_models SET make_id = 47 WHERE make_id = 8;
UPDATE IGNORE vehicle_models SET make_id = 41 WHERE make_id = 1;
DELETE FROM vehicle_models WHERE make_id IN (1, 8);

-- Finally, delete the redundant makes
DELETE FROM vehicle_makes WHERE id IN (1, 8);

SET FOREIGN_KEY_CHECKS = 1;

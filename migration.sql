-- ==============================================================================
--   Migrates production database to new release.
--   1) make a backup of the entire database. 
--   2) Get a fresh export from RAM.
--   3) Stop all servers, deactivate plugins.  Rename code folders.
--   4) clone repositories.  Reestablish links for plugins. (do not start plugins)
--   5) run this SQL script.
--   6) start plugins and servers.
--   7) run DoorKing backfill.
--   8) Clean up vendor entries for staff, etc.
--   9) Test.
--
-- STEP 1: Create New Tables (ac_households, ac_vehicles, ac_credential_types, ac_credentials)
-- Matches Fsbhoa_Core_Repository definitions and types
-- ==============================================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `ac_households` (
    `household_id` int NOT NULL AUTO_INCREMENT,
    `household_name` varchar(100) NOT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`household_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ac_vehicles` (
    `vehicle_id` int NOT NULL AUTO_INCREMENT,
    `household_id` int NOT NULL,
    `license_plate` varchar(20) DEFAULT NULL,
    `plate_state` varchar(5) DEFAULT 'CA',
    `vehicle_type` varchar(50) DEFAULT 'Automobile' COMMENT 'e.g., Automobile, RV, Golf Cart',
    `make` varchar(50) DEFAULT NULL,
    `model` varchar(50) DEFAULT NULL,
    `color` varchar(30) DEFAULT NULL,
    `year` int DEFAULT NULL,
    `lpr_access_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 if LPR opens gate',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`vehicle_id`),
    KEY `idx_license_plate_state` (`license_plate`, `plate_state`),
    KEY `idx_vehicle_household` (`household_id`),
    CONSTRAINT `fk_vehicle_household` FOREIGN KEY (`household_id`) REFERENCES `ac_households` (`household_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ac_credential_types` (
    `id` int NOT NULL AUTO_INCREMENT,
    `type_code` varchar(50) NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `type_code` (`type_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ac_credentials` (
    `id` int NOT NULL AUTO_INCREMENT,
    `cardholder_id` int NOT NULL,
    `vehicle_id` int DEFAULT NULL COMMENT 'Optional: Link directly to a vehicle',
    `credential_type` varchar(50) NOT NULL,
    `credential_value` varchar(50) NOT NULL,
    `status` ENUM('active', 'disabled', 'inactive') NOT NULL DEFAULT 'inactive',
    `issue_date` date DEFAULT (CURRENT_DATE),
    `expiration_date` datetime DEFAULT NULL,
    `notes` text,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type_value` (`credential_type`, `credential_value`),
    KEY `idx_cred_cardholder` (`cardholder_id`),
    KEY `idx_cred_vehicle` (`vehicle_id`),
    CONSTRAINT `fk_cred_type_code` FOREIGN KEY (`credential_type`) REFERENCES `ac_credential_types` (`type_code`) ON UPDATE CASCADE,
    CONSTRAINT `fk_cred_cardholder` FOREIGN KEY (`cardholder_id`) REFERENCES `ac_cardholders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cred_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `ac_vehicles` (`vehicle_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `ac_credential_types` (`type_code`, `description`) VALUES
    ('MIFARE_BADGE', 'Photo ID Badge'),
    ('RFID', 'Generic Pedestrian RFID'),
    ('DK_DIR_CODE', 'DoorKing Directory Code'),
    ('DK_DIR_OPT_IN', 'Resident authorization to list phone in visitor directory'),
    ('DK_ENTRY_CODE', 'DoorKing Entry PIN Code'),
    ('DK_WINDSHIELD', 'Windshield RFID Tag'),
    ('WIEGAND_26', 'Auxiliary Wiegand 26-bit Credential')
ON DUPLICATE KEY UPDATE description=VALUES(description);


-- ==============================================================================
-- STEP 2: Evolve ac_cardholders Schema to Match Core Repository
-- ==============================================================================

-- 2a. Rename card_status -> cardholder_status
ALTER TABLE `ac_cardholders`
    CHANGE COLUMN `card_status` `cardholder_status` varchar(20) NOT NULL DEFAULT 'inactive';

-- 2b. Add company, household_id, cardholder_type, and matching FKs/indexes
ALTER TABLE `ac_cardholders`
    ADD COLUMN `company` varchar(100) DEFAULT NULL AFTER `last_name`,
    ADD COLUMN `household_id` int DEFAULT NULL AFTER `property_id`,
    ADD COLUMN `cardholder_type` enum('resident','vendor') NOT NULL DEFAULT 'resident' AFTER `cardholder_status`,
    ADD KEY `idx_household_id` (`household_id`),
    ADD KEY `idx_cardholder_status` (`cardholder_status`),
    ADD KEY `idx_cardholder_type_status` (`cardholder_type`, `cardholder_status`),
    ADD CONSTRAINT `fk_ac_cardholders_household` FOREIGN KEY (`household_id`) REFERENCES `ac_households` (`household_id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- 2c. Backfill existing vendors based on legacy resident_type
UPDATE `ac_cardholders`
    SET `cardholder_type` = 'vendor'
    WHERE `resident_type` = 'Vendor';

-- ==============================================================================
-- STEP 3: Household Generation & Linkage (No String Comparisons / Collation Safe)
-- ==============================================================================

-- 3a. Lodge (Property 480): Mark as vendor and create 1:1 individual households
UPDATE `ac_cardholders`
    SET `cardholder_type` = 'vendor'
    WHERE `property_id` = 480;

DROP TEMPORARY TABLE IF EXISTS tmp_lodge_sync;
CREATE TEMPORARY TABLE tmp_lodge_sync (
    `row_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `cardholder_id` INT NOT NULL,
    `household_name` VARCHAR(100) NOT NULL
);

INSERT INTO tmp_lodge_sync (`cardholder_id`, `household_name`)
SELECT
    id,
    CONCAT(COALESCE(NULLIF(TRIM(last_name), ''), 'Vendor'), ', ', COALESCE(NULLIF(TRIM(first_name), ''), 'Staff'), ' (Vendor)')
FROM `ac_cardholders`
WHERE `property_id` = 480;

INSERT INTO `ac_households` (`household_name`)
SELECT `household_name` FROM tmp_lodge_sync ORDER BY `row_id` ASC;

SET @first_lodge_hh = LAST_INSERT_ID();
UPDATE `ac_cardholders` c
JOIN tmp_lodge_sync t ON c.id = t.cardholder_id
SET c.household_id = @first_lodge_hh + (t.row_id - 1);

DROP TEMPORARY TABLE IF EXISTS tmp_lodge_sync;

-- 3b. Standard Residents (Property != 480): Group strictly by property_id
DROP TEMPORARY TABLE IF EXISTS tmp_property_sync;
CREATE TEMPORARY TABLE tmp_property_sync (
    `row_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT NOT NULL,
    `household_name` VARCHAR(100) NOT NULL
);

INSERT INTO tmp_property_sync (`property_id`, `household_name`)
SELECT
    property_id,
    CONCAT(MAX(TRIM(last_name)), ' Household')
FROM `ac_cardholders`
WHERE `property_id` IS NOT NULL
  AND `property_id` != 480
GROUP BY `property_id`;

INSERT INTO `ac_households` (`household_name`)
SELECT `household_name` FROM tmp_property_sync ORDER BY `row_id` ASC;

SET @first_prop_hh = LAST_INSERT_ID();
UPDATE `ac_cardholders` c
JOIN tmp_property_sync t ON c.property_id = t.property_id
SET c.household_id = @first_prop_hh + (t.row_id - 1)
WHERE c.property_id != 480;

DROP TEMPORARY TABLE IF EXISTS tmp_property_sync;


-- ==============================================================================
-- STEP 4: Data Migration (Migrate legacy pedestrian RFIDs into ac_credentials)
-- ==============================================================================

INSERT INTO `ac_credentials` (
    `cardholder_id`,
    `credential_type`,
    `credential_value`,
    `status`,
    `issue_date`,
    `expiration_date`
)
SELECT
    `id` AS `cardholder_id`,
    'MIFARE_BADGE' AS `credential_type`,
    `rfid_id` AS `credential_value`,
    CASE
        WHEN `cardholder_status` = 'active' THEN 'active'
        WHEN `cardholder_status` = 'disabled' THEN 'disabled'
        ELSE 'inactive'
    END AS `status`,
    COALESCE(`card_issue_date`, CURRENT_DATE) AS `issue_date`,
    COALESCE(`card_expiry_date`, '2099-12-31 23:59:59') AS `expiration_date`
FROM `ac_cardholders`
WHERE `rfid_id` IS NOT NULL AND `rfid_id` != '';

-- ==============================================================================
-- STEP 5: Clean Up Dropped Legacy Columns on ac_cardholders
-- ==============================================================================

ALTER TABLE `ac_cardholders`
    DROP COLUMN `active_rfid`,
    DROP COLUMN `rfid_id`,
    DROP COLUMN `card_issue_date`,
    DROP COLUMN `card_expiry_date`;


SET FOREIGN_KEY_CHECKS = 1;


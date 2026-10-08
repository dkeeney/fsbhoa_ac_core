-- ==============================================================================
-- Migrates production database to new release.
--
-- STEP 1: Create New Tables
-- ==============================================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `ac_households` (
  `household_id` int NOT NULL AUTO_INCREMENT,
  `household_name` varchar(100) NOT NULL,
  `primary_cardholder_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`household_id`),
  KEY `idx_primary_cardholder_id` (`primary_cardholder_id`)
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

CREATE TABLE IF NOT EXISTS `ac_vehicle_log` (
  `vehicle_log_id` int NOT NULL AUTO_INCREMENT,
  `event_timestamp` datetime(3) NOT NULL,
  `gate_identifier` varchar(50) NOT NULL,
  `auth_id` varchar(50) DEFAULT NULL COMMENT 'Credential value or PIN presented at gate',
  `auth_type` varchar(32) DEFAULT NULL COMMENT 'Credential type for auth_id',
  `lpr_plate_string` varchar(20) DEFAULT NULL,
  `lpr_confidence` tinyint UNSIGNED DEFAULT NULL COMMENT 'Speco OCR confidence score 0-100',
  `is_circumvention` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 if loop traversed without auth',
  `context_image_data` mediumblob DEFAULT NULL,
  `lpr_image_data` mediumblob DEFAULT NULL,
  `raw_details` json DEFAULT NULL COMMENT 'Timing delta, Shelly loop state transitions, daemon telemetry',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`vehicle_log_id`),
  KEY `idx_timestamp_gate` (`event_timestamp`, `gate_identifier`),
  KEY `idx_lpr_plate` (`lpr_plate_string`),
  KEY `idx_auth_id` (`auth_id`),
  KEY `idx_circumvention` (`is_circumvention`, `event_timestamp`)
  KEY `idx_auth_lookup` (`auth_type`, `auth_id`)
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
-- STEP 2: Evolve ac_cardholders Schema
-- ==============================================================================
ALTER TABLE `ac_cardholders`
  CHANGE COLUMN `card_status` `cardholder_status` varchar(20) NOT NULL DEFAULT 'inactive';

ALTER TABLE `ac_cardholders`
  ADD COLUMN `company` varchar(100) DEFAULT NULL AFTER `last_name`,
  ADD COLUMN `household_id` int DEFAULT NULL AFTER `property_id`,
  ADD COLUMN `cardholder_type` enum('resident','vendor') NOT NULL DEFAULT 'resident' AFTER `cardholder_status`,
  ADD KEY `idx_household_id` (`household_id`),
  ADD KEY `idx_cardholder_status` (`cardholder_status`),
  ADD KEY `idx_cardholder_type_status` (`cardholder_type`, `cardholder_status`),
  ADD CONSTRAINT `fk_ac_cardholders_household` FOREIGN KEY (`household_id`) REFERENCES `ac_households` (`household_id`) ON DELETE SET NULL ON UPDATE CASCADE;

UPDATE `ac_cardholders`
  SET `cardholder_type` = 'vendor'
  WHERE `resident_type` = 'Vendor';

-- ==============================================================================
-- STEP 3: Household Generation & Linkage (with Primary Assignment)
-- ==============================================================================

-- 3a. Lodge (Property 480): Vendors are their own primary
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
WHERE `property_id` = 480
ORDER BY id ASC;

INSERT INTO `ac_households` (`household_name`, `primary_cardholder_id`)
SELECT `household_name`, `cardholder_id` FROM tmp_lodge_sync ORDER BY `row_id` ASC;

SET @first_lodge_hh = LAST_INSERT_ID();
UPDATE `ac_cardholders` c
JOIN tmp_lodge_sync t ON c.id = t.cardholder_id
SET c.household_id = @first_lodge_hh + (t.row_id - 1);

DROP TEMPORARY TABLE IF EXISTS tmp_lodge_sync;

-- 3b. Standard Residents (Property != 480): Group by property_id & pick primary
DROP TEMPORARY TABLE IF EXISTS tmp_property_sync;
CREATE TEMPORARY TABLE tmp_property_sync (
  `row_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `property_id` INT NOT NULL,
  `household_name` VARCHAR(100) NOT NULL,
  `primary_cardholder_id` INT DEFAULT NULL
);

INSERT INTO tmp_property_sync (`property_id`, `household_name`, `primary_cardholder_id`)
SELECT
  property_id,
  CONCAT(MAX(TRIM(last_name)), ' Household'),
  COALESCE(
    MIN(CASE WHEN cardholder_status NOT IN ('archived', 'purged') THEN id ELSE NULL END),
    MIN(id)
  ) AS primary_cardholder_id
FROM `ac_cardholders`
WHERE `property_id` IS NOT NULL
  AND `property_id` != 480
GROUP BY `property_id`
ORDER BY property_id ASC;

INSERT INTO `ac_households` (`household_name`, `primary_cardholder_id`)
SELECT `household_name`, `primary_cardholder_id` FROM tmp_property_sync ORDER BY `row_id` ASC;

SET @first_prop_hh = LAST_INSERT_ID();
UPDATE `ac_cardholders` c
JOIN tmp_property_sync t ON c.property_id = t.property_id
SET c.household_id = @first_prop_hh + (t.row_id - 1)
WHERE c.property_id != 480;

DROP TEMPORARY TABLE IF EXISTS tmp_property_sync;

-- ==============================================================================
-- STEP 4: Data Migration (Migrate legacy RFIDs into ac_credentials)
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
-- STEP 5: Clean Up Dropped Columns & Add Primary FK
-- ==============================================================================
ALTER TABLE `ac_cardholders`
  DROP COLUMN `active_rfid`,
  DROP COLUMN `rfid_id`,
  DROP COLUMN `card_issue_date`,
  DROP COLUMN `card_expiry_date`;

-- Add the primary cardholder FK after all ids and tables exist
ALTER TABLE `ac_households`
  ADD CONSTRAINT `fk_household_primary_cardholder` 
  FOREIGN KEY (`primary_cardholder_id`) 
  REFERENCES `ac_cardholders` (`id`) 
  ON DELETE SET NULL 
  ON UPDATE CASCADE;

-- ==============================================================================
-- STEP 6: Door network address (kiosk stations are identified by IP address)
-- ==============================================================================
ALTER TABLE `ac_doors`
  ADD COLUMN IF NOT EXISTS `ip_address` varchar(45) DEFAULT NULL COMMENT 'Network address of the device at this door (e.g. a kiosk station browser).' AFTER `door_delay`;

-- ==============================================================================
-- STEP 7: TEST door role (system doors for automated tests, hidden from the live monitor map)
-- ==============================================================================
ALTER TABLE `ac_doors`
  MODIFY COLUMN `door_role` enum('INNER_GATE','ENTRY_GATE','PERIMETER','KIOSK','TEST') DEFAULT NULL COMMENT 'TEST = system door for automated tests; hidden from the live monitor map.';

-- The Regression Test Controller's door (created by fsbhoa_ac_uhppote) was stored as KIOSK.
UPDATE `ac_doors` d
  JOIN `ac_controllers` c ON d.controller_record_id = c.controller_record_id
  SET d.door_role = 'TEST'
  WHERE c.uhppoted_device_id = 88888888;

SET FOREIGN_KEY_CHECKS = 1;

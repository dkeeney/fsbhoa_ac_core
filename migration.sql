-- ==============================================================================
-- STEP 1: Create the new tables (ac_credential_types and ac_credentials)
-- ==============================================================================

CREATE TABLE IF NOT EXISTS ac_credential_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_code VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the credential types table with our primary badge type
INSERT IGNORE INTO ac_credential_types (type_code, description) 
VALUES ('MIFARE_BADGE', 'Standard RFID Badge');

CREATE TABLE IF NOT EXISTS ac_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cardholder_id INT NOT NULL,
    credential_type VARCHAR(50) NOT NULL,
    credential_value VARCHAR(100) NOT NULL,
    status VARCHAR(20) DEFAULT 'invalid',
    issue_date DATE DEFAULT NULL,
    expiration_date DATE NOT NULL DEFAULT '2099-12-31',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (cardholder_id),
    INDEX (credential_value),
    FOREIGN KEY (credential_type) REFERENCES ac_credential_types(type_code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================================================================
-- STEP 3: Rename card_status to cardholder_status in ac_cardholders
-- (We do this before migrating data so our SELECT statement below is clean)
-- ==============================================================================

ALTER TABLE ac_cardholders 
CHANGE COLUMN card_status cardholder_status VARCHAR(20) DEFAULT 'active';

-- ==============================================================================
-- STEP 2, 4, & 5: Migrate the data
-- Move rfid_id, map the status ('active'/'disabled'/'invalid'), and move dates
-- ==============================================================================

INSERT INTO ac_credentials (
    cardholder_id, 
    credential_type, 
    credential_value, 
    status, 
    issue_date, 
    expiration_date
)
SELECT 
    id as cardholder_id,
    'MIFARE_BADGE' as credential_type,
    rfid_id as credential_value,
    CASE 
        WHEN cardholder_status = 'active' THEN 'active'
        WHEN cardholder_status = 'disabled' THEN 'disabled'
        ELSE 'invalid'
    END as status,
    card_issue_date as issue_date,
    COALESCE(DATE(card_expiry_date), '2099-12-31') as expiration_date
FROM ac_cardholders
WHERE rfid_id IS NOT NULL AND rfid_id != '';

-- ==============================================================================
-- STEP 6: Clean up the legacy columns
-- Drop rfid_id, card_issue_date, and card_expiry_date from ac_cardholders
-- ==============================================================================

ALTER TABLE ac_cardholders
DROP COLUMN active_rfid,
DROP COLUMN rfid_id,
DROP COLUMN card_issue_date,
DROP COLUMN card_expiry_date;

-- ==============================================================================
-- STEP 7: Update ac_vehicles for shared vehicles and nullable plates
-- ==============================================================================

-- Make the license_plate column nullable to support empty plate saves
ALTER TABLE ac_vehicles MODIFY COLUMN license_plate VARCHAR(20) NULL DEFAULT NULL;

-- Drop the unique constraint so multiple households can register the same shared vehicle
ALTER TABLE ac_vehicles DROP INDEX idx_license_plate_state;

-- Add it back as a standard index so LPR queries remain fast
CREATE INDEX idx_license_plate_state ON ac_vehicles (license_plate, plate_state);

INSERT IGNORE INTO ac_credential_types (type_code, description)
VALUES ('DK_DIR_OPT_IN', 'Resident authorization to list phone in visitor directory');

-- ==============================================================================
-- STEP 8: Add cardholder_type discriminator for dual-entity management
-- ==============================================================================

ALTER TABLE ac_cardholders
ADD COLUMN cardholder_type ENUM('resident', 'vendor') NOT NULL DEFAULT 'resident' AFTER cardholder_status,
ADD INDEX idx_cardholder_type_status (cardholder_type, cardholder_status);

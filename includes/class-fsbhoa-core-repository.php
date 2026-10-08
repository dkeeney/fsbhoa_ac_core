<?php
/**
 * Core Database Repository
 * Handles schema creation, migrations, and core data access.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/includes
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Core_Repository {

    /**
     * Creates all necessary database tables for the Access Control system.
     * Designed to be called on plugin activation.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Ensure we catch any database errors during installation
        $wpdb->show_errors();

        // ====================================================================
        // 1. EXISTING TABLES (Ordered to satisfy Foreign Key constraints)
        // ====================================================================

        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_property` (
            `property_id` int NOT NULL AUTO_INCREMENT,
            `house_number` varchar(20) NOT NULL COMMENT 'e.g., 123, 456A',
            `street_name` varchar(180) NOT NULL COMMENT 'e.g., Main St, Oak Ave',
            `street_address` varchar(200) NOT NULL,
            `notes` text,
            `origin` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'Indicates if the record was from a csv import or added manually',
            PRIMARY KEY (`property_id`),
            UNIQUE KEY `idx_street_address_unique` (`street_address`),
            KEY `idx_street_name_house_number` (`street_name`,`house_number`)
        ) ENGINE=InnoDB {$charset_collate};");


        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_households` (
            `household_id` int NOT NULL AUTO_INCREMENT,
            `household_name` varchar(100) NOT NULL,
            `primary_cardholder_id` int DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`household_id`),
            KEY `idx_primary_cardholder_id` (`primary_cardholder_id`)
        ) ENGINE=InnoDB {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_cardholders` (
            `id` int NOT NULL AUTO_INCREMENT,
            `first_name` varchar(100) DEFAULT NULL,
            `last_name` varchar(100) DEFAULT NULL,
            `company`  varchar(100) DEFAULT NULL,
            `title` varchar(50) DEFAULT NULL,
            `import_first_name` varchar(255) DEFAULT NULL,
            `import_last_name` varchar(255) DEFAULT NULL,
            `property_id` int DEFAULT NULL,
            `household_id` int DEFAULT NULL,
            `email` varchar(255) DEFAULT NULL,
            `email_used` tinyint(1) NOT NULL DEFAULT '0',
            `phone` varchar(30) DEFAULT NULL,
            `phone_type` varchar(10) DEFAULT 'Mobile',
            `photo` longblob,
            `cardholder_status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active = current; archived; purged. Vendors are never archived. Badge status is in ac_credentials.',
            `cardholder_type` enum('resident','vendor') NOT NULL DEFAULT 'resident',
            `notes` text,
            `resident_type` varchar(50) DEFAULT 'Resident Owner',
            `origin` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'Indicates if the record was from a csv import or added manually',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` datetime DEFAULT NULL,
            `groups_csv` text COMMENT 'Comma-separated list of group IDs the user belonged to at the time of deletion.',
            PRIMARY KEY (`id`),
            KEY `idx_last_name` (`last_name`),
            KEY `idx_first_name` (`first_name`),
            KEY `idx_property_id` (`property_id`),
            KEY `idx_household_id` (`household_id`),
            KEY `idx_email` (`email`),
            KEY `idx_phone` (`phone`),
            KEY `idx_phone_type` (`phone_type`),
            KEY `idx_cardholder_status` (`cardholder_status`),
            KEY `idx_cardholder_type_status` (`cardholder_type`, `cardholder_status`),
            KEY `idx_resident_type` (`resident_type`),
            CONSTRAINT `fk_ac_cardholders_property` FOREIGN KEY (`property_id`) REFERENCES `ac_property` (`property_id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_ac_cardholders_household` FOREIGN KEY (`household_id`) REFERENCES `ac_households` (`household_id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB {$charset_collate};");



        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_groups` (
            `group_id` int NOT NULL AUTO_INCREMENT,
            `group_name` varchar(100) NOT NULL,
            `group_description` text COMMENT 'Notes field to describe the group''s purpose.',
            `is_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = Disabled, 1 = Enabled. A disabled group grants no permissions.',
            `has_all_access` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'If set to 1, this group has 24/7 access to all doors, overriding other permissions.',
            `is_default` tinyint(1) NOT NULL DEFAULT '0',
            PRIMARY KEY (`group_id`),
            UNIQUE KEY `unique_group_name` (`group_name`)
        ) ENGINE=InnoDB {$charset_collate};");


        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_cardholder_groups` (
            `cardholder_id` int NOT NULL,
            `group_id` int NOT NULL,
            PRIMARY KEY (`cardholder_id`,`group_id`),
            KEY `group_id` (`group_id`),
            CONSTRAINT `fk_cardholder_groups_cardholder` FOREIGN KEY (`cardholder_id`) REFERENCES `ac_cardholders` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cardholder_groups_group` FOREIGN KEY (`group_id`) REFERENCES `ac_groups` (`group_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_collate};");


        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_controllers` (
            `controller_record_id` int NOT NULL AUTO_INCREMENT,
            `uhppoted_device_id` bigint NOT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `door_count` tinyint(1) NOT NULL DEFAULT '4' COMMENT 'Number of doors supported by this controller model (e.g., 1, 2, or 4)',
            `is_static_ip` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 = DHCP, 1 = Static',
            `friendly_name` varchar(100) NOT NULL,
            `notes` text,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `type` enum('UHPPOTE','VIRTUAL_KIOSK','REGRESSION_TEST') NOT NULL DEFAULT 'UHPPOTE' COMMENT 'Defines the functional class of the device. REGRESSION_TEST = fake controller 88888888 for automated tests (never synced).',
            PRIMARY KEY (`controller_record_id`),
            UNIQUE KEY `idx_uhppoted_device_id_unique` (`uhppoted_device_id`),
            UNIQUE KEY `idx_friendly_name_unique` (`friendly_name`)
        ) ENGINE=InnoDB {$charset_collate};");



        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_doors` (
            `door_record_id` int NOT NULL AUTO_INCREMENT,
            `controller_record_id` int NOT NULL,
            `door_number_on_controller` tinyint NOT NULL COMMENT 'Typically 1-4, representing the door output on the controller board.',
            `friendly_name` varchar(100) NOT NULL,
            `notes` text,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `map_x` int DEFAULT '0',
            `map_y` int DEFAULT '0',
            `amenity_role` varchar(20) DEFAULT NULL,
            `door_role` enum('INNER_GATE','ENTRY_GATE','PERIMETER','KIOSK','TEST') DEFAULT NULL COMMENT 'TEST = system door for automated tests; hidden from the live monitor map.',
            `amenity_id` varchar(255) DEFAULT NULL COMMENT 'Comma-separated list of amenity IDs covered by this door.',
            `door_delay` tinyint UNSIGNED NOT NULL DEFAULT '3' COMMENT 'Seconds the relay remains energized (UHPPOTE door-delay)',
            `ip_address` varchar(45) DEFAULT NULL COMMENT 'Network address of the device at this door (e.g. a kiosk station browser).',
            PRIMARY KEY (`door_record_id`),
            UNIQUE KEY `idx_friendly_name_unique` (`friendly_name`),
            UNIQUE KEY `idx_controller_door_unique` (`controller_record_id`,`door_number_on_controller`),
            KEY `idx_fk_controller_record_id` (`controller_record_id`),
            CONSTRAINT `fk_ac_doors_controller` FOREIGN KEY (`controller_record_id`) REFERENCES `ac_controllers` (`controller_record_id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB {$charset_collate};");



        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_schedules` (
            `schedule_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `start_date` date DEFAULT NULL,
            `end_date` date DEFAULT NULL,
            `is_default` tinyint(1) NOT NULL DEFAULT '0',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`schedule_id`)
        ) ENGINE=InnoDB {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_group_permissions` (
            `permission_id` int NOT NULL AUTO_INCREMENT,
            `group_id` int NOT NULL,
            `schedule_id` int UNSIGNED NOT NULL DEFAULT '1',
            `controller_id` int UNSIGNED DEFAULT NULL,
            `door_id` int DEFAULT NULL,
            `is_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = Disabled, 1 = Enabled.',
            `start_time` time NOT NULL,
            `end_time` time NOT NULL,
            `on_mon` tinyint(1) NOT NULL DEFAULT '0',
            `on_tue` tinyint(1) NOT NULL DEFAULT '0',
            `on_wed` tinyint(1) NOT NULL DEFAULT '0',
            `on_thu` tinyint(1) NOT NULL DEFAULT '0',
            `on_fri` tinyint(1) NOT NULL DEFAULT '0',
            `on_sat` tinyint(1) NOT NULL DEFAULT '0',
            `on_sun` tinyint(1) NOT NULL DEFAULT '0',
            PRIMARY KEY (`permission_id`),
            KEY `idx_group_id` (`group_id`),
            KEY `idx_door_id` (`door_id`),
            KEY `fk_controller_id` (`controller_id`),
            KEY `fk_group_permissions_schedule` (`schedule_id`),
            CONSTRAINT `fk_group_permissions_door` FOREIGN KEY (`door_id`) REFERENCES `ac_doors` (`door_record_id`) ON DELETE SET NULL,
            CONSTRAINT `fk_group_permissions_group` FOREIGN KEY (`group_id`) REFERENCES `ac_groups` (`group_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_group_permissions_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `ac_schedules` (`schedule_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_collate};");



        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_access_log` (
            `log_id` int NOT NULL AUTO_INCREMENT,
            `event_timestamp` datetime(3) NOT NULL,
            `controller_identifier` varchar(50) NOT NULL COMMENT 'Identifier from uhppoted-rest, e.g., uhppoted_device_id or IP',
            `door_number` tinyint NOT NULL,
            `rfid_id` varchar(8) DEFAULT NULL,
            `cardholder_id` int DEFAULT NULL,
            `event_type_code` int NOT NULL COMMENT 'Numeric code for the event type from uhppoted-rest',
            `event_description` varchar(255) NOT NULL,
            `access_granted` tinyint(1) DEFAULT NULL COMMENT 'TRUE for granted, FALSE for denied, NULL if not applicable',
            `raw_event_details` text,
            `guest_count` int DEFAULT '0',
            `amenity_name` varchar(100) DEFAULT NULL COMMENT 'The confirmed amenity name (e.g., Pool, Courts) at time of access.',
            PRIMARY KEY (`log_id`),
            KEY `idx_event_timestamp` (`event_timestamp`),
            KEY `idx_controller_identifier` (`controller_identifier`),
            KEY `idx_rfid_id_access_log` (`rfid_id`),
            KEY `idx_cardholder_id_access_log` (`cardholder_id`),
            KEY `idx_event_type_code` (`event_type_code`),
            KEY `idx_access_granted` (`access_granted`),
            CONSTRAINT `fk_ac_access_log_cardholder` FOREIGN KEY (`cardholder_id`) REFERENCES `ac_cardholders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB {$charset_collate};");



        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_print_log` (
            `log_id` int NOT NULL AUTO_INCREMENT,
            `system_job_id` varchar(50) NOT NULL COMMENT 'Unique ID generated by our Java app for this print request',
            `printer_job_id` varchar(50) DEFAULT NULL COMMENT 'Job ID from the Zebra SDK',
            `cardholder_id` int DEFAULT NULL,
            `rfid_id` varchar(8) DEFAULT NULL,
            `print_request_data` json DEFAULT NULL COMMENT 'Original JSON payload sent from PHP, for retries or auditing',
            `sdk_image_name` varchar(255) DEFAULT NULL COMMENT 'Name of the image file saved via SDK for this job, if any',
            `status` varchar(30) NOT NULL COMMENT 'e.g., submitted, printing, completed_ok, failed_error, cancelled_by_user',
            `status_message` text COMMENT 'Error messages or detailed status from SDK/printer',
            `submitted_by_user` varchar(100) DEFAULT NULL COMMENT 'WordPress username who initiated the print',
            `submitted_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (`log_id`),
            UNIQUE KEY `idx_system_job_id_unique` (`system_job_id`),
            KEY `idx_printer_job_id_print_log` (`printer_job_id`),
            KEY `idx_cardholder_id_print_log` (`cardholder_id`),
            KEY `idx_rfid_id_print_log` (`rfid_id`),
            KEY `idx_status_print_log` (`status`),
            KEY `idx_submitted_by_user_print_log` (`submitted_by_user`),
            CONSTRAINT `fk_ac_print_log_cardholder` FOREIGN KEY (`cardholder_id`) REFERENCES `ac_cardholders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB {$charset_collate};");


        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_task_list` (
            `id` int NOT NULL AUTO_INCREMENT,
            `schedule_id` int UNSIGNED NOT NULL DEFAULT '1',
            `controller_id` int DEFAULT NULL,
            `door_number` tinyint DEFAULT NULL COMMENT '1-4, or NULL for all doors on the targeted controller(s)',
            `task_type` tinyint NOT NULL COMMENT 'Numeric ID for the uhppoted task type',
            `start_time` time NOT NULL,
            `on_mon` tinyint(1) NOT NULL DEFAULT '0',
            `on_tue` tinyint(1) NOT NULL DEFAULT '0',
            `on_wed` tinyint(1) NOT NULL DEFAULT '0',
            `on_thu` tinyint(1) NOT NULL DEFAULT '0',
            `on_fri` tinyint(1) NOT NULL DEFAULT '0',
            `on_sat` tinyint(1) NOT NULL DEFAULT '0',
            `on_sun` tinyint(1) NOT NULL DEFAULT '0',
            `enabled` tinyint(1) NOT NULL DEFAULT '1',
            `notes` text,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_controller_id_task_list` (`controller_id`),
            KEY `idx_enabled_task_list` (`enabled`),
            KEY `fk_task_list_schedule` (`schedule_id`),
            CONSTRAINT `fk_ac_task_list_controller` FOREIGN KEY (`controller_id`) REFERENCES `ac_controllers` (`controller_record_id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_task_list_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `ac_schedules` (`schedule_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_collate};");



        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_amenities` (
            `id` int NOT NULL AUTO_INCREMENT COMMENT 'Primary key for the amenity',
            `name` varchar(100) NOT NULL COMMENT 'The display name of the amenity (e.g., Billiards, Library)',
            `image_url` varchar(255) DEFAULT NULL COMMENT 'URL to an image for the amenity',
            `display_order` int NOT NULL DEFAULT '0' COMMENT 'An integer to control the sort order of buttons on the kiosk UI',
            `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether the amenity is active and should be displayed (1=Active, 0=Inactive)',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_name_unique` (`name`)
        ) ENGINE=InnoDB {$charset_collate};");



        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_pending_changes` (
            `id` int NOT NULL AUTO_INCREMENT,
            `change_type` varchar(20) NOT NULL DEFAULT 'cardholder',
            `record_id` int UNSIGNED DEFAULT NULL,
            `change_data` longtext,
            `changed_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB {$charset_collate};");




        // ====================================================================
        // 2. NEW CREDENTIAL & VEHICLE TABLES (Phase 2 Evolution)
        // ====================================================================

        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_credential_types` (
            `id` int NOT NULL AUTO_INCREMENT,
            `type_code` varchar(50) NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `type_code` (`type_code`)
        ) ENGINE=InnoDB {$charset_collate};");


        // Vehicles must be created BEFORE credentials to satisfy the foreign key constraint
        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_vehicles` (
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
        ) ENGINE=InnoDB {$charset_collate};");




        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_credentials` (
            `id` int NOT NULL AUTO_INCREMENT,
            `cardholder_id` int NOT NULL,
            `vehicle_id` int DEFAULT NULL COMMENT 'Optional: Link directly to a vehicle',
            `credential_type` varchar(50) NOT NULL,
            `credential_value` varchar(50) NOT NULL,
            `status` ENUM('active', 'disabled', 'inactive') NOT NULL DEFAULT 'inactive',
            `issue_date` date DEFAULT CURRENT_TIMESTAMP,
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
        ) ENGINE=InnoDB {$charset_collate};");


        $wpdb->query("CREATE TABLE IF NOT EXISTS `ac_vehicle_log` (
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
        ) ENGINE=InnoDB {$charset_collate};");



        // ====================================================================
        // 3. INITIAL SEED DATA
        // ====================================================================
        
        $wpdb->query("INSERT IGNORE INTO `ac_credential_types` (`type_code`, `description`) VALUES
            ('MIFARE_BADGE', 'Photo ID Badge'),
            ('DK_DIR_CODE', 'DoorKing Directory Code'),
            ('DK_DIR_OPT_IN', 'Resident authorization to list phone in visitor directory'),
            ('DK_ENTRY_CODE', 'DoorKing Entry PIN Code'),
            ('DK_WINDSHIELD', 'Windshield RFID Tag');");
    }
}


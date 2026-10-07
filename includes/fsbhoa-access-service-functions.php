<?php 
class Fsbhoa_Access_Service {
    
    /**
     * Main entry point for processing all access events (Kiosk, Gate, Future Readers).
     * Handles rate limit, amenity logic, and database write.
     * * @param array $log_data Standardized event data array.
     * @return int|WP_Error Returns log_id on success, WP_Error on failure/rate limit.
     */
    public static function process_and_write_event( $log_data ) {
        
        // 1. Go find the cardholder
        self::lookup_cardholder( $log_data );
        
        // 2. Rate Limit Check
        if (self::is_rate_limited($log_data)) {
            return new WP_Error('rate_limit', 'Event is a duplicate or rate-limited.');
        }

        // 3. Amenity Processing (Assigns amenity_name, updates guest_count, clears preceding logs)
        $log_data = self::process_amenity_logic($log_data);

        // 4. For a denied card, add everything known about it to the reason,
        //    so staff can see why it was rejected (archived, disabled, expired, ...).
        if ( isset( $log_data['access_granted'] ) && (int) $log_data['access_granted'] === 0 && ! empty( $log_data['rfid_id'] ) ) {
            $reason = trim( (string) ( $log_data['event_description'] ?? '' ) );
            $diagnosis = self::describe_credential( $log_data['rfid_id'] );
            $log_data['event_description'] = mb_substr( $reason === '' ? $diagnosis : $reason . ' | ' . $diagnosis, 0, 255 );
        }

        // 5. Database Write
        $log_id = self::write_to_access_log($log_data);
        if ( !$log_id ) {
            // Database write failed (write_to_access_log returned false).
             return new WP_Error('db_error', 'Failed to insert event into access log.');
        }

        // 6. Notification (Centralized logic)
        // If we successfully wrote the log, notify the monitor service.
        self::send_notification_to_monitor($log_id);

        // Return the log ID on success.
        return $log_id;
    }

    /**
     * Looks up the cardholder and populates cardholder_id in the log array.
     * @param array &$log_data The standardized log data array (passed by reference).
     */
    private static function lookup_cardholder( &$log_data ) {
        global $wpdb;

        $rfid = $log_data['rfid_id'];

        // 1. Check for no RFID read 
        if ( empty( $rfid ) ) {
            $log_data['cardholder_id'] = 0;
            $log_data['access_granted'] = $log_data['access_granted'] ?? 0; // Default to denied
            $log_data['event_description'] = $log_data['event_description'] ?? 'No Card Read';
            return; // Cannot proceed without an RFID
        }

        // 2. Lookup Cardholder using the rfid_id
        $cardholder = $wpdb->get_row( $wpdb->prepare( "
            SELECT ch.id, ch.first_name, ch.last_name, ch.property_id, ch.resident_type
            FROM ac_cardholders ch
            JOIN ac_credentials cred ON ch.id = cred.cardholder_id
            WHERE cred.credential_value = %s
            AND cred.credential_type = 'MIFARE_BADGE'
            AND cred.status = 'active' AND ch.cardholder_status = 'active' ", $rfid )
        );

        if ( $cardholder ) {
            $log_data['cardholder_id'] = absint( $cardholder->id );
            $log_data['resident_type'] = $cardholder->resident_type;
        } else {
            // No active badge for an active cardholder. Keep the caller's reason (e.g. the
            // controller's); describe_credential() adds the details when the event is written.
            $log_data['cardholder_id'] = 0;
            $log_data['access_granted'] = 0; // Ensure access is denied if card is unknown
            if ( empty( $log_data['event_description'] ) ) {
                $log_data['event_description'] = 'Card not found';
            }
        }
    }

    /**
     * Describes every credential with this number, whatever its status, for a denied event.
     * Example: "Badge 19107883: inactive; test card (#936) archived 2025-10-08"
     * @param string $value The credential number as read (may be zero-padded).
     * @return string One description per matching credential, or "not in the system".
     */
    public static function describe_credential( $value ) {
        global $wpdb;

        $value   = trim( (string) $value );
        $trimmed = ltrim( $value, '0' );
        if ( $trimmed === '' ) {
            $trimmed = '0';
        }

        // Readers zero-pad numbers (00010011), but credentials may be stored without padding.
        $creds = $wpdb->get_results( $wpdb->prepare(
            "SELECT cred.credential_type, cred.credential_value, cred.status, cred.expiration_date,
                    ct.description AS type_name,
                    ch.id AS cardholder_id, ch.first_name, ch.last_name, ch.cardholder_status, ch.deleted_at,
                    v.license_plate, v.plate_state
             FROM ac_credentials cred
             LEFT JOIN ac_credential_types ct ON ct.type_code = cred.credential_type
             LEFT JOIN ac_cardholders ch ON ch.id = cred.cardholder_id
             LEFT JOIN ac_vehicles v ON v.vehicle_id = cred.vehicle_id
             WHERE cred.credential_value IN (%s, %s)
             ORDER BY cred.credential_type = 'MIFARE_BADGE' DESC, cred.id DESC",
            $value,
            $trimmed
        ) );

        if ( $wpdb->last_error ) {
            error_log( 'FSBHOA ACCESS: describe_credential DB error: ' . $wpdb->last_error );
            return 'Card ' . $value . ': lookup failed';
        }
        if ( empty( $creds ) ) {
            return 'Card ' . $value . ' is not in the system';
        }

        $now   = current_time( 'timestamp' );
        $parts = [];
        foreach ( $creds as $cred ) {
            $type   = $cred->credential_type === 'MIFARE_BADGE' ? 'Badge' : ( $cred->type_name ?: $cred->credential_type );
            $status = $cred->status !== '' ? $cred->status : 'no status';
            if ( ! empty( $cred->expiration_date ) && strtotime( $cred->expiration_date ) < $now ) {
                $status .= ', expired ' . date( 'Y-m-d', strtotime( $cred->expiration_date ) );
            }
            $text = $type . ' ' . $cred->credential_value . ': ' . $status;

            if ( ! empty( $cred->license_plate ) ) {
                $text .= '; vehicle ' . $cred->license_plate . ( $cred->plate_state ? ' (' . $cred->plate_state . ')' : '' );
            }

            if ( $cred->cardholder_id ) {
                $name = trim( $cred->first_name . ' ' . $cred->last_name );
                $text .= '; ' . ( $name !== '' ? $name : 'cardholder' ) . ' (#' . $cred->cardholder_id . ') ' . $cred->cardholder_status;
                if ( in_array( $cred->cardholder_status, [ 'archived', 'purged' ], true ) && ! empty( $cred->deleted_at ) ) {
                    $text .= ' ' . date( 'Y-m-d', strtotime( $cred->deleted_at ) );
                }
            } else {
                $text .= '; no cardholder';
            }

            $parts[] = $text;
        }

        return implode( ' | ', $parts );
    }
    

    /**
     * ---RATE LIMITING---
     * Checks if the event is a duplicate based on recent activity (rate limit check).
     * Concept:
     *   We are trying to avoid recording multiple swipes that a user might perform
     *   all at about the same time.  It is just redundant. So ignore.
     *   1) If there is no RFID (not a valid cardholder) then just skip the check.
     *   2) If this is a system user, it means we are running a regression test, then
     *   also skip the check (allow the duplicate).
     *   3) If no rate limit time is specified, then skip the check.
     *   4) If the current swipe is the
     *        A. the same cardholder
     *        B. within this rate limit time
     *        C. at the same gate
     *        D. and the same access granted status
     *        E. and the same amenity
     *      Then ignore this swipe entirely.
     *
     *   We need to handle the case were a cardholder signs in for "Pool (0 Guests)" and then 
     *   immediately realized he forgot his guests and sign in again for "Pool (2 Guests)", 
     *   in this case we update the previous record with the new count and count this as duplicate.
     *
     * @param array $log_data Standardized event data array.
     * @return bool Returns true if the event should be rate-limited (discarded), false otherwise.
     */
    private static function is_rate_limited( $log_data ) {
        global $wpdb;

        // there should be a cardholder_id for a valid cardholder.
        if ( !isset($log_data['cardholder_id']) || $log_data['cardholder_id'] === 0 ) {
            return false;
        }
    
        // Time window for duplicate check (e.g., last 30 seconds for the same event)
        // If no rate limit time is configured, skip rate limiting.
        $minutes = get_option('fsbhoa_ac_rate_limit_minutes', 10);
        if ($minutes > 0) {
            // Use WordPress's time functions to ensure the correct timezone.
            $time_ago_unix = current_time('timestamp') - ($minutes * MINUTE_IN_SECONDS);
            $time_ago = date('Y-m-d H:i:s', $time_ago_unix);
            $guest_count = isset($log_data['guest_count']) ? $log_data['guest_count'] : 0;
            $amenity_name = isset($log_data['amenity_name']) ? $log_data['amenity_name'] : '';

            // Query for a similar event for the same cardholder, door, and granted status, within time.
            // --- DYNAMIC QUERY BUILDING ---
            $sql = "SELECT log.log_id, log.guest_count
                FROM ac_access_log log
                INNER JOIN ac_cardholders card ON log.cardholder_id = card.id
                WHERE log.cardholder_id = %d
                  AND log.controller_identifier = %s
                  AND log.door_number = %d
                  AND log.access_granted = %d
                  AND log.event_timestamp >= %s
                  AND card.resident_type != 'System'";

            $prepare_args = [
                $log_data['cardholder_id'],
                $log_data['controller_identifier'],
                $log_data['door_number'],
                $log_data['access_granted'],
                $time_ago
            ];

            // LOGIC FIX:
            // 1. If this is a KIOSK swipe, we HAVE an amenity name (e.g. 'Pool'), so we must match it.
            //    (Prevents a sign-in for 'Pool' from blocking a sign-in for 'Gym').
            // 2. If this is a HARDWARE swipe, amenity is empty. We ignore the amenity column.
            //    (We rely on the Door Number being the unique physical constraint).
            if ( !empty($amenity_name) ) {
                $sql .= " AND log.amenity_name = %s";
                $prepare_args[] = $amenity_name;
            }

            $sql .= " ORDER BY log.event_timestamp DESC LIMIT 1";

            // Run the query
            $query = $wpdb->prepare($sql, $prepare_args);
            $recent_log = $wpdb->get_row( $query );

            if ($recent_log) {
                // found a recent record already in the log. See if the guest_count changed.  If so, update it.
                $new_guests = isset($log_data['guest_count']) ? (int)$log_data['guest_count'] : 0;
                $old_guests = (int)$recent_log->guest_count;

                if ( $new_guests !== $old_guests ) {
                    // Update the existing record to reflect the corrected count
                    
                    $new_description = 'Amenity: ' . esc_html($amenity_name);
                    // Optional: You could append the count here if you like details in the text
                    if ($new_guests > 0) $new_description .= " (+$new_guests guests)";

                    $wpdb->update(
                        'ac_access_log',
                        [
                            'guest_count'       => $new_guests,
                            'event_description' => $new_description,
                            'event_timestamp'   => $log_data['event_timestamp'] // Bump time to now
                        ],
                        ['log_id' => $recent_log->log_id],
                        ['%d', '%s', '%s'],
                        ['%d']
                    );

                    // Notify monitor so the screen updates immediately
                    self::send_notification_to_monitor((int) $recent_log->log_id);
                    error_log( sprintf("[RATE LIMIT] previous event's guest count modified for Cardholder rfid %s at %s.", 
                        $log_data['rfid_id'], $log_data['event_timestamp']
                    ) );
                    return true; // Stop processing
                }
                error_log( sprintf("[RATE LIMIT] Discarding duplicate event for Cardholder rfid %s at %s.", 
                    $log_data['rfid_id'], $log_data['event_timestamp']
                ) );
                return true; // Rate limited!
            }
        }

        return false; // Not rate limited.
    }


    /**
     * ---AMENITY TRACKING---
     * Processes all access events to apply amenity rules and clean up provisional logs.
     *  The concept:
     *    There are multiple routes that a cardholder may take to get to an amenity.
     *    We are trying to guess as to which amenity they arrived at.
     *    1) Cardholder entered the Lodge during business hours and login at the kiosk.
     *       The kiosk swipe will collect the cardholder's intended amenity.
     *       1a) Cardholder then proceeded to the amenity (no inner gates).
     *           The kiosk record holds the amenity. Done.
     *       1b) Cardholder then proceeded to an inner gate who's default amenity was the 
     *           same as recorded amenity. We already know the amenity but to avoid double counting
     *           we move the amenity (and guest count) from the kiosk record
     *           to the inner doors record. Done.
     *       1c) Cardholder then proceeded to an inner gate who's default amenity was NOT 
     *           the same as recorded amenity. We assume this cardholder is visiting more 
     *           than one amenity so we leave the recorded amenity on the kiosk record and
     *           record another record with the gate's default amenity as the access amenity.
     *    2) Cardholder entered the After Hours gate.  We don't collect the amenity so we guess
     *       the amenity could be "Courts".
     *       2a) Cardholder then proceeded to the amenity (no inner gates).
     *           The After Hours gate record holds the guessed amenity (Courts). Done
     *       2b) Cardholder then proceeded to an inner gate.
     *           The role of the inner gate is a better guess so we assign the
     *           inner gates role as the amenity and clear the after hours gate's
     *           amenity to avoid double counting. Done.
     *    3) Cardholder entered one of the perimeter gates.
     *       Record the event with the gate's default amenity as the access amenity. Done.
     *
     * NOTE: This relies on the door_role and amenity_id fields being correctly set in ac_doors.
     *
     * @param array $log_data Standardized event data array.
     * @return array The updated log data array ready for insertion.
     */
    private static function process_amenity_logic( $log_data ) {
        // 1. Skip if access was denied or no cardholder
        if ( $log_data['access_granted'] !== 1 || $log_data['cardholder_id'] === 0 ) {
            return $log_data;
        }

        // 2. Get Door/Gate Info (Assuming the API handlers retrieve this and 
        //    populate $log_data['door_info'])
        $door_info = $log_data['door_info'] ?? self::get_door_info(
            $log_data['controller_identifier'], 
            $log_data['door_number']
        );

        if ( !$door_info ) {
            return $log_data; // No mapping found for this gate
        }

        // 3. Define Time Window and Entry Gate Info (Used for look-back queries)
        $minutes = get_option('fsbhoa_ac_amenity_clear_minutes', 10);
        $time_ago = date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * MINUTE_IN_SECONDS));
    
        // --- SCENARIO A: INNER GATE SWIPE (Highest Priority: Clearing/Transfer) ---
        if ( $door_info->door_role === 'INNER_GATE' ) {

            $recent_entry = self::find_recent_entry_gate( $log_data, $time_ago );
    
            // 1. Get the amenity names associated with the inner gate (array of names)
            // NOTE: door_info now includes door_record_id
            $inner_amenity_names = self::get_door_amenity_names( $door_info->door_record_id ); 
    
            // Use the first amenity name in the list for log messages if multiple exist
            $inner_amenity_name_for_log = empty($inner_amenity_names) ? 'Unknown Amenity' : $inner_amenity_names[0];

            if ( $recent_entry && !empty($recent_entry->amenity_name) ) {

                // Check if the single amenity name from the preceding entry is in the INNER GATE's list of names
                if ( in_array( $recent_entry->amenity_name, $inner_amenity_names ) ) { 
                    //  Check the ROLE of the previous gate.
                    // If the previous gate was ALSO an INNER_GATE, do not consume it.
                    // We only consume "Provisional" entries (Entry Gates and Kiosks).
                    if ( $recent_entry->previous_door_role === 'INNER_GATE' ) {
                         // From one INNER_GATE to another.
                         // Treat this as a standard, standalone entry (Same as Scenario 3 below)
                         $log_data['event_description'] = 'Amenity: ' . esc_html($recent_entry->amenity_name);
                         $log_data['amenity_name'] = $recent_entry->amenity_name;
                         $log_data['guest_count'] = 0; // Usually 0 for re-entry/duplicate
                    } else {
                         // ACTION 1B/2B: Transfer amenity, clear preceding log (Amenity Match)
                         $log_data['event_description'] = 'Amenity: ' . esc_html($recent_entry->amenity_name);
                         $log_data['amenity_name'] = $recent_entry->amenity_name;
                         $log_data['guest_count'] = $recent_entry->guest_count;
                         self::clear_preceding_log($recent_entry->log_id, $door_info->friendly_name);
                    }

                } else {
                    // SCENARIO 1C: Mismatch (Concurrent Usage)
                    $log_data['event_description'] = 'Amenity: ' . esc_html($inner_amenity_name_for_log);
                    $log_data['amenity_name'] = $inner_amenity_name_for_log;
                    $log_data['guest_count'] = 0; 

                    // CHECK: Was the previous record just a "Hardware Guess" (ENTRY_GATE)?
                    // If so, delete it. It was wrong.
                    if ( $recent_entry->previous_door_role === 'ENTRY_GATE' ) {
                         self::clear_preceding_log($recent_entry->log_id,  $door_info->friendly_name);
                    }
                }

            } else {
                // SCENARIO 3: Inner Gate (No Preceding Registration)
                $log_data['event_description'] = 'Amenity: ' . esc_html($inner_amenity_name_for_log);
                $log_data['amenity_name'] = $inner_amenity_name_for_log;
                $log_data['guest_count'] = 0;
            }
        }
    
        // --- SCENARIO B: ENTRY GATE SWIPE (Sets Provisional Amenity) ---
        else if ( $door_info->door_role === 'ENTRY_GATE' ) {
        
            // SCENARIO 2A: Physical Entry Gate (West Gate/Front Door) - Set Provisional
            // (Kiosk sign-ins arrive on KIOSK-role doors; see Scenario C.)

            // 1. Get the amenity names associated with the entry gate (array of names)
            $entry_amenity_names = self::get_door_amenity_names( $door_info->door_record_id ); 

            // 2. Set the provisional name to the FIRST amenity in the list (if any)
            $provisional_name = empty($entry_amenity_names) ? 'Unknown Amenity' : $entry_amenity_names[0];


            $log_data['event_description'] = 'Amenity: ' . esc_html($provisional_name);
            $log_data['amenity_name'] = $provisional_name;
            $log_data['guest_count'] = 0;
        }
        // --- SCENARIO C: KIOSK (Definitive / Human Intent) ---
        else if ( $door_info->door_role === 'KIOSK' ) {
            // We TRUST the input. The amenity_name is already in $log_data.
            if ( !empty($log_data['amenity_name']) ) {
                $log_data['event_description'] = 'Amenity: ' . esc_html($log_data['amenity_name']);
            } else {
                 $log_data['event_description'] = 'Kiosk Check-in: General';
            }
        }
    
        // --- SCENARIO D: PERIMETER GATE (Default/Log Only) ---
        // If the role is 'PERIMETER' or unknown, amenity_name remains NULL (as initialized).
        // So, just log it.

        return $log_data;
    }
    

    /**
     * Writes the finalized log data array to the ac_access_log table.
     * @param array $log_data Standardized event data array.
     * @return int|bool log_id (int) on successful insert, false on database failure.
     */
    private static function write_to_access_log( $log_data ) {
        global $wpdb;

        $insert_data = $log_data;

        // Remove fields that are not in the table.
        // This is NOT really neccessary but just a security precaution.
        unset($insert_data['door_info']);   
        unset($insert_data['resident_type']);

        // Change cardholder_id to NULL for bad cards so it will not break Foreign Key constraints
        if ( isset( $insert_data['cardholder_id'] ) && $insert_data['cardholder_id'] === 0 ) {
            $insert_data['cardholder_id'] = null;
        }

        // Explicitly define formats for insert (using placeholders for safety)
        $formats = array_fill(0, count($insert_data), '%s'); 

        $result = $wpdb->insert( 'ac_access_log', $insert_data, $formats );

        if ( $result === false ) {
            error_log( sprintf(
                "CRITICAL DB ERROR: Failed to log access event. Error: %s. Data: %s",
                $wpdb->last_error,
                json_encode($log_data)
            ));
            return false;
        }

        return $wpdb->insert_id; // Return the new log_id
    }



    /**
     * Finds a recent log entry that should be cleared by the current inner gate swipe.
     * Searches for Kiosk or ENTRY_GATE swipes within the time limit.
     *
     * Now fetches the 'door_role' of the previous gate to help us distinguish 
     * between a 'KIOSK' (Keep) and an 'ENTRY_GATE' (Delete).
     *
     * @param array $current_log_data Standardized event data array for the current swipe.
     * @param string $time_ago The minimum timestamp to search from.
     * @return object|null The preceding log entry object, or null if not found.
     */
    private static function find_recent_entry_gate( $current_log_data, $time_ago ) {
        global $wpdb;

        // JOIN to ac_doors to find out the ROLE of the gate that created the previous log.
        $query = $wpdb->prepare("
            SELECT 
                log.log_id, 
                log.amenity_name, 
                log.guest_count,
                log.controller_identifier,
                d.door_role as previous_door_role
            FROM ac_access_log log
            LEFT JOIN ac_controllers c ON log.controller_identifier = c.uhppoted_device_id
            LEFT JOIN ac_doors d ON (c.controller_record_id = d.controller_record_id AND log.door_number = d.door_number_on_controller)
            WHERE log.cardholder_id = %d
              AND log.event_timestamp >= %s
              AND log.amenity_name IS NOT NULL
              AND log.access_granted = 1
            ORDER BY log.event_timestamp DESC
            LIMIT 1
        ",
            $current_log_data['cardholder_id'],
            $time_ago
        );

        return $wpdb->get_row($query);
    }


    /**
     * Clears the amenity data from a preceding log entry after it has been transferred
     * to an INNER_GATE swipe (preventing double counting).
     * Then notify the real-time screen that this record was modified.
     *
     * @param int $log_id The log_id of the record to be cleared (the preceding Kiosk/Entry Gate swipe).
     * @param string $inner_gate_name The name of the inner gate that performed the clearing.
     * @return bool True on successful update.
     */
    private static function clear_preceding_log( $log_id, $inner_gate_name ) {
        global $wpdb;
    
        $cleared_description = $wpdb->prepare(
            'Access to %s', 
            esc_html($inner_gate_name)
        );

        $result =  $wpdb->update(
            'ac_access_log',
            [
                'amenity_name' => NULL,
                'guest_count' => 0,
                'event_description' => $cleared_description,
            ],
            ['log_id' => $log_id],
            ['%s', '%d', '%s'],
            ['%d']
        );
        // Only send if the database update was successful and rows were changed.
        if ( $result !== false && $result > 0 ) {
            self::send_notification_to_monitor( $log_id );
        }
        return $result;
    }


    /**
     * Retrieves essential door configuration (role and amenity ID) based on hardware identifiers.
     * This function uses the new door_role and amenity_id fields.
     *
     * @param string $controller_identifier The controller serial (hardware or virtual).
     * @param int $door_number The door number on the controller.
     * @return object|null Door information object with friendly_name, door_role, and amenity_id.
     */
    private static function get_door_info( $controller_identifier, $door_number ) {
        global $wpdb;

        // --- SPECIAL CASES FOR VIRTUAL/TESTING EVENTS ---
        
        // Scenario 3: Admin Console Simulation (Door 255)
        if ( $door_number == 255 ) {
            return (object)[
                'friendly_name'  => 'Admin Console',
                'door_role'      => 'VIRTUAL',
                'amenity_id'     => '',
                'door_record_id' => 0
            ];
        }

        // Scenario 1: Unit Tests (Door 254)
        if ( $door_number == 254 ) {
            return (object)[
                'friendly_name'  => 'System Unit Test',
                'door_role'      => 'VIRTUAL',
                'amenity_id'     => '',
                'door_record_id' => 0
            ];
        }

        // --- STANDARD LOOKUP ---

        // NOTE: This query joins controllers and doors to find the configuration mapping.
        $door_info_query = $wpdb->prepare("
            SELECT 
                d.friendly_name, 
                d.door_role, 
                d.amenity_id,
                d.door_record_id
            FROM ac_doors d
            INNER JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id
            WHERE c.uhppoted_device_id = %s 
              AND d.door_number_on_controller = %d
        ", 
            $controller_identifier, 
            $door_number
        );

        $result = $wpdb->get_row($door_info_query);

        if ($result === null && !empty($wpdb->last_error)) {
             error_log("DB ERROR: " . $wpdb->last_error);
             return null;
        }
        
        // Fallback for Diagnostics: 
        // If we tested a Controller/Door combo that doesn't exist in the DB yet,
        // return a generic name instead of failing.
        if ( $result === null ) {
            return (object)[
                'friendly_name'  => 'Test: Controller ' . $controller_identifier . ' Door ' . $door_number,
                'door_role'      => 'TEST',
                'amenity_id'     => '',
                'door_record_id' => 0
            ];
        }

        return $result;
    }


    /**
     * Retrieves the list of amenity names covered by a single door.
     * Uses the comma-separated string stored in the modified amenity_id column.
     *
     * @param int $door_record_id The ID of the door record.
     * @return array Array of amenity names (e.g., ['Pool', 'Spa']). Returns empty array if none are configured.
     */
    private static function get_door_amenity_names( $door_record_id ) {
        global $wpdb;

        // 1. Get the list of IDs from the single amenity_id column
        $id_list_string = $wpdb->get_var( $wpdb->prepare( 
            "SELECT amenity_id FROM ac_doors WHERE door_record_id = %d", 
            $door_record_id
        ) );

        if ( empty( $id_list_string ) ) {
            return [];
        }

        // 2. Parse the comma-separated string into a clean array of IDs
        $ids = array_map('absint', explode(',', $id_list_string));
        $ids = array_filter($ids); // Removes 0s and empty strings

        if ( empty($ids) ) {
            return [];
        }
    
        // 3. Prepare and execute the query for names using call_user_func_array for dynamic arguments
        $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Arguments array: [query_string, id1, id2, id3, ...]
        $prepare_args = array_merge(
            [ "SELECT name FROM ac_amenities WHERE id IN ({$id_placeholders})" ],
            $ids
        );

        $prepared_query = call_user_func_array([$wpdb, 'prepare'], $prepare_args);
        $names = $wpdb->get_col( $prepared_query );

        // 4. Sanitize and return the array of names
        return array_map('sanitize_text_field', $names);
    }

    /**
     * Private helper to send notifications to monitor_service.
     * The Go services communicate internally via HTTPS on the local host.
     * @param int $log_id The ID of the newly created log entry.
     */
    private static function send_notification_to_monitor($log_id){
        $monitor_port = get_option('fsbhoa_ac_monitor_port', 8082);
        $tls_cert_path = get_option('fsbhoa_ac_tls_cert_path', '');
        $tls_key_path  = get_option('fsbhoa_ac_tls_key_path', '');
        $protocol = (!empty($tls_cert_path) && !empty($tls_key_path)) ? 'https' : 'http';
        $monitor_url = sprintf('%s://127.0.0.1:%d/notify', $protocol, $monitor_port);
        $post_body = [ 'event_id' => (int)$log_id ];

        $monitor_response = wp_remote_post($monitor_url, [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'      => json_encode($post_body),
            'timeout'   => 5,
            'sslverify' => false,
        ]);

        if( is_wp_error( $monitor_response ) ){
            error_log('MONITOR-NOTIFY-ERROR: Failed to notify monitor_service. Reason: ' . $monitor_response->get_error_message());
        } else {
            error_log('MONITOR-NOTIFY-SUCCESS: Successfully sent notification to monitor_service for event_id: ' . $log_id);
        }
    }
}

<?php
/**
 * General utility functions for Cardholder operations.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/includes
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Archives a cardholder by setting their status to 'archived', saving their
 * current group memberships to a CSV field, and then removing their active
 * memberships to instantly revoke permissions.
 *
 * @param int $cardholder_id The ID of the cardholder to archive.
 * @return true|WP_Error True on success, WP_Error object on failure.
 */
function fsbhoa_archive_and_delete_cardholder( $cardholder_id ) {
    global $wpdb;
    $table_cardholders = 'ac_cardholders';
    $table_memberships = 'ac_cardholder_groups';

    // 1. Fetch the cardholder's current group memberships to preserve them.
    $group_ids = $wpdb->get_col( $wpdb->prepare( "SELECT group_id FROM {$table_memberships} WHERE cardholder_id = %d", $cardholder_id ) );
    if ( $wpdb->last_error ) {
        return new WP_Error( 'db_error_fetch_groups', 'Database error while fetching groups for archival. DB Error: ' . esc_html( $wpdb->last_error ) );
    }
    $groups_csv = implode( ',', $group_ids );

    // 2. Atomically update the cardholder record to mark it as archived.
    $updated = $wpdb->update(
        $table_cardholders,
        [
            'cardholder_status' => 'archived',
            'deleted_at'  => current_time( 'mysql', 1 ), // Use WordPress's timezone-aware timestamp
            'groups_csv'  => $groups_csv
        ],
        [ 'id' => $cardholder_id ],
        [ '%s', '%s', '%s' ], // Data formats
        [ '%d' ]  // Where format
    );

    if ( false === $updated ) {
        return new WP_Error( 'db_error_update', 'Database error while archiving the cardholder. DB Error: ' . esc_html( $wpdb->last_error ) );
    }
    // --- Deactivate all credentials belonging to this archived user ---
    $wpdb->update('ac_credentials', ['status' => 'archived'], ['cardholder_id' => $cardholder_id], ['%s'], ['%d']);

    // 3. For security, remove all active group memberships to revoke permissions immediately.
    $deleted = $wpdb->delete( $table_memberships, [ 'cardholder_id' => $cardholder_id ], [ '%d' ] );
    if ( false === $deleted ) {
        // This is a critical failure, but the user is already archived. Log it.
        error_log( 'FSBHOA SECURITY WARNING: Failed to delete group memberships for archived cardholder ID ' . $cardholder_id . '. DB Error: ' . $wpdb->last_error );
    }
    fsbhoa_log_pending_change('cardholder', $cardholder_id);

    return true;
}


/**
 * Universal helper to restore an archived cardholder.
 * Resolves household placement, re-evaluates active status, restores groups_csv,
 * re-activates credentials, logs changes, and fires the core lifecycle hook.
 *
 * @param int      $cardholder_id The ID of the cardholder to restore.
 * @param int|null $target_household_id Optional explicit household ID. If null, auto-resolves.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function fsbhoa_restore_cardholder( $cardholder_id, $target_household_id = null ) {
    global $wpdb;
    $cardholder_id = absint( $cardholder_id );

    if ( ! $cardholder_id ) {
        return new WP_Error( 'invalid_id', __( 'Invalid cardholder ID.', 'fsbhoa-ac' ) );
    }

    $table_cardholders = 'ac_cardholders';
    $table_memberships = 'ac_cardholder_groups';

    // 1. Fetch the cardholder record
    $cardholder = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, property_id, household_id, groups_csv, cardholder_type FROM {$table_cardholders} WHERE id = %d",
        $cardholder_id
    ) );

    if ( ! $cardholder ) {
        return new WP_Error( 'not_found', __( 'Cardholder record not found.', 'fsbhoa-ac' ) );
    }

    // 2. Resolve Household Assignment
    $final_household_id = $cardholder->household_id;

    if ( null !== $target_household_id ) {
        $final_household_id = absint( $target_household_id );
    } elseif ( 'resident' === $cardholder->cardholder_type && ! empty( $cardholder->property_id ) ) {
        // Auto-detect household based on current active occupants at this property
        $active_resident = $wpdb->get_row( $wpdb->prepare(
            "SELECT household_id FROM {$table_cardholders}
             WHERE property_id = %d
               AND cardholder_status IN ('active', 'inactive')
               AND cardholder_type = 'resident'
               AND household_id IS NOT NULL AND household_id > 0
             LIMIT 1",
            $cardholder->property_id
        ) );

        if ( $active_resident && ! empty( $active_resident->household_id ) ) {
            $final_household_id = absint( $active_resident->household_id );
        } elseif ( empty( $final_household_id ) ) {
            $wpdb->insert( 'ac_households', [ 'household_name' => 'Restored Household' ] );
            $final_household_id = $wpdb->insert_id;
        }
    }

    // 3. Determine Status: 'active' if they possess valid credentials, else 'inactive'
    $has_credential = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM ac_credentials WHERE cardholder_id = %d LIMIT 1",
        $cardholder_id
    ) );
    $new_status = $has_credential ? 'active' : 'inactive';

    // 4. Update the cardholder row
    $updated = $wpdb->update(
        $table_cardholders,
        [
            'cardholder_status' => $new_status,
            'household_id'      => $final_household_id,
            'deleted_at'        => null,
            'groups_csv'        => null,
        ],
        [ 'id' => $cardholder_id ],
        [ '%s', '%d', null, null ],
        [ '%d' ]
    );

    if ( false === $updated ) {
        return new WP_Error( 'db_error', 'Database error restoring cardholder: ' . $wpdb->last_error );
    }

    // 5. Restore Group Memberships from groups_csv backup
    if ( ! empty( $cardholder->groups_csv ) ) {
        $wpdb->delete( $table_memberships, [ 'cardholder_id' => $cardholder_id ] );
        $group_ids = explode( ',', $cardholder->groups_csv );
        foreach ( $group_ids as $gid ) {
            $gid = absint( trim( $gid ) );
            if ( $gid > 0 ) {
                $wpdb->insert( $table_memberships, [
                    'cardholder_id' => $cardholder_id,
                    'group_id'      => $gid,
                ], [ '%d', '%d' ] );
            }
        }
    }

    // 6. Reactivate Credentials in Database
    $wpdb->update(
        'ac_credentials',
        [ 'status' => 'active' ],
        [ 'cardholder_id' => $cardholder_id ],
        [ '%s' ],
        [ '%d' ]
    );

    // 7. Fire the Core Lifecycle Hook (DoorKing synchronizes DIR, ENTRY, OPT_IN here)
    do_action( 'fsbhoa_core_cardholder_restored', $cardholder_id );

    // 8. Log the Change for hardware sync
    $creds = $wpdb->get_col( $wpdb->prepare(
        "SELECT credential_value FROM ac_credentials WHERE cardholder_id = %d AND status = 'active'",
        $cardholder_id
    ) );
    $log_data = json_encode( [
        'credentials' => empty( $creds ) ? '' : implode( ',', $creds ),
        'action'      => 'cardholder_restored',
    ] );
    fsbhoa_log_pending_change( 'cardholder', $cardholder_id, $log_data );

    return true;
}

/**
 * Shared helper to merge a source cardholder into a destination cardholder.
 * Encapsulates full transaction logic, credential reassignment, group merging,
 * photo transfer, access log re-linking, source purging, and property cleanup.
 *
 * @param int $destination_id The surviving record.
 * @param int $source_id      The record being absorbed and purged.
 * @return bool|WP_Error      True on success, WP_Error on failure.
 */
function fsbhoa_merge_cardholders( $destination_id, $source_id ) {
    global $wpdb;

    $destination_id = absint( $destination_id );
    $source_id      = absint( $source_id );

    if ( ! $destination_id || ! $source_id || $destination_id === $source_id ) {
        return new WP_Error( 'invalid_ids', 'Invalid source or destination cardholder ID specified.' );
    }

    $wpdb->query( 'START TRANSACTION' );

    $table_cardholders = 'ac_cardholders';
    $table_access_log  = 'ac_access_log';
    $table_properties  = 'ac_property';

    // Fetch and lock source record
    $source_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_cardholders} WHERE id = %d FOR UPDATE", $source_id ), ARRAY_A );
    if ( ! $source_record ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'source_not_found', 'Could not find or lock the source record to merge.' );
    }

    // Fetch and lock destination record
    $dest_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_cardholders} WHERE id = %d FOR UPDATE", $destination_id ), ARRAY_A );
    if ( ! $dest_record ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'destination_not_found', 'Could not find or lock the destination record.' );
    }
    if ( ( isset( $source_record['cardholder_type'] ) && $source_record['cardholder_type'] !== 'resident' ) ||
         ( isset( $dest_record['cardholder_type'] ) && $dest_record['cardholder_type'] !== 'resident' ) ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'invalid_cardholder_type', 'Merge operations are restricted to resident cardholders only.' );
    }

    // 1. Move credentials from source to destination
    $source_creds = $wpdb->get_results( $wpdb->prepare( "SELECT id, credential_type, credential_value FROM ac_credentials WHERE cardholder_id = %d", $source_id ) );

    foreach ( $source_creds as $cred ) {
        $dest_has_exact_cred = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM ac_credentials WHERE cardholder_id = %d AND credential_type = %s AND credential_value = %s LIMIT 1",
            $destination_id, $cred->credential_type, $cred->credential_value
        ) );

        if ( $dest_has_exact_cred ) {
            $wpdb->delete( 'ac_credentials', [ 'id' => $cred->id ], [ '%d' ] );
        } else {
            $result = $wpdb->query( $wpdb->prepare(
                "UPDATE ac_credentials SET cardholder_id = %d, status = 'active' WHERE id = %d",
                $destination_id, $cred->id
            ) );
            if ( false === $result && ! empty( $wpdb->last_error ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'credential_error', 'Database error while merging credentials: ' . $wpdb->last_error );
            }
        }
    }

    // 2. Evaluate active status for destination cardholder
    $has_any_cred = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM ac_credentials WHERE cardholder_id = %d AND status = 'active' LIMIT 1", $destination_id ) );
    $new_status = $has_any_cred ? 'active' : 'inactive';

    $manual_property_id = ! empty( $source_record['property_id'] ) ? absint( $source_record['property_id'] ) : 0;

    // 3. Update cardholder data fields
    $sql = $wpdb->prepare(
        "UPDATE {$table_cardholders} SET
            first_name = %s, last_name = %s, title = %s,
            email = %s, email_used = %d, phone = %s, phone_type = %s,
            cardholder_status = %s, notes = %s, resident_type = %s
        WHERE id = %d",
        $source_record['first_name'],
        $source_record['last_name'],
        $source_record['title'],
        $source_record['email'],
        $source_record['email_used'],
        $source_record['phone'],
        $source_record['phone_type'],
        $new_status,
        $source_record['notes'],
        $source_record['resident_type'],
        $destination_id
    );
    $updated = $wpdb->query( $sql );
    if ( false === $updated ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'data_error', 'Database error while merging cardholder data: ' . $wpdb->last_error );
    }

    // 4. Restore group memberships
    if ( ! empty( $source_record['groups_csv'] ) ) {
        $table_memberships = 'ac_cardholder_groups';
        $group_ids_to_restore = explode( ',', $source_record['groups_csv'] );

        foreach ( $group_ids_to_restore as $group_id ) {
            $group_id = absint( $group_id );
            if ( $group_id > 0 ) {
                $inserted = $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$table_memberships} (cardholder_id, group_id) VALUES (%d, %d)",
                    $destination_id, $group_id
                ) );
                if ( false === $inserted ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new WP_Error( 'group_error', 'Database error while merging group memberships: ' . $wpdb->last_error );
                }
            }
        }
    }

    // 5. Update photo data if present
    if ( ! empty( $source_record['photo'] ) ) {
        $updated_photo = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table_cardholders} SET photo = %s WHERE id = %d",
            $source_record['photo'], $destination_id
        ) );
        if ( false === $updated_photo ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'photo_error', 'Database error while merging photo data: ' . $wpdb->last_error );
        }
    }

    // 6. Re-link historical access logs
    $relinked = $wpdb->update( $table_access_log, [ 'cardholder_id' => $destination_id ], [ 'cardholder_id' => $source_id ], [ '%d' ], [ '%d' ] );
    if ( false === $relinked ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'log_error', 'Database error while re-linking access logs: ' . $wpdb->last_error );
    }

    // 7. Purge the source record
    $purged = $wpdb->update( $table_cardholders, [ 'cardholder_status' => 'purged' ], [ 'id' => $source_id ], [ '%s' ], [ '%d' ] );
    if ( false === $purged ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'purge_error', 'Database error while purging source record: ' . $wpdb->last_error );
    }

    // 8. Clean up orphaned property if manual
    if ( $manual_property_id > 0 ) {
        $property_origin = $wpdb->get_var( $wpdb->prepare( "SELECT origin FROM {$table_properties} WHERE property_id = %d", $manual_property_id ) );
        if ( $property_origin === 'manual' ) {
            $remaining_cardholders = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_cardholders} WHERE property_id = %d", $manual_property_id ) );
            if ( $remaining_cardholders == 0 ) {
                $wpdb->delete( $table_properties, [ 'property_id' => $manual_property_id ], [ '%d' ] );
            }
        }
    }


    // Broadcast merge event before commit so plugins can resolve credentials in the same transaction
    do_action( 'fsbhoa_core_cardholders_merged', $destination_id, $source_id, $dest_record, $source_record );

    $wpdb->query( 'COMMIT' );
    fsbhoa_log_pending_change( 'cardholder', $destination_id );

    return true;
}

/**
 * Changes a cardholder's resident type and moves them to the appropriate household.
 *
 * @param int    $cardholder_id The ID of the cardholder.
 * @param string $new_type      The new resident type (e.g. 'Landlord', 'Tenant', etc.).
 * @return array|WP_Error      Array with new household details on success, WP_Error on failure.
 */
function fsbhoa_change_cardholder_resident_type( $cardholder_id, $new_type ) {
    global $wpdb;

    $cardholder_id = absint( $cardholder_id );
    $new_type      = sanitize_text_field( $new_type );

    if ( ! $cardholder_id || empty( $new_type ) ) {
        return new WP_Error( 'invalid_input', __( 'Invalid cardholder ID or resident type.', 'fsbhoa-ac' ) );
    }

    $current = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, property_id, household_id, last_name, resident_type FROM ac_cardholders WHERE id = %d",
        $cardholder_id
    ) );

    if ( ! $current ) {
        return new WP_Error( 'not_found', __( 'Cardholder not found.', 'fsbhoa-ac' ) );
    }

    $is_landlord         = ( strcasecmp( $new_type, 'Landlord' ) === 0 );
    $target_household_id = 0;

    // 1. Find existing target household matching the new role at this property
    if ( $is_landlord ) {
        $target_household_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT household_id FROM ac_cardholders
             WHERE property_id = %d
               AND cardholder_status IN ('active', 'inactive')
               AND resident_type = 'Landlord'
               AND id != %d
               AND household_id IS NOT NULL
             LIMIT 1",
            $current->property_id, $cardholder_id
        ) );
    } else {
        $target_household_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT household_id FROM ac_cardholders
             WHERE property_id = %d
               AND cardholder_status IN ('active', 'inactive')
               AND resident_type != 'Landlord'
               AND resident_type NOT IN ('Contractor', 'Staff', 'Other', 'Emergency', 'Delivery')
               AND id != %d
               AND household_id IS NOT NULL
             LIMIT 1",
            $current->property_id, $cardholder_id
        ) );
    }

    // 2. Provision a new household if none exists for this role
    if ( empty( $target_household_id ) ) {
        $suffix = $is_landlord ? " (Landlord)" : " Household";
        $household_name = sanitize_text_field( ($current->last_name ?: 'New') . $suffix );
        $wpdb->insert( 'ac_households', [ 'household_name' => $household_name ] );
        $target_household_id = (int) $wpdb->insert_id;

        if ( ! $target_household_id ) {
            return new WP_Error( 'db_error', __( 'Failed to create new household.', 'fsbhoa-ac' ) );
        }
    }

    // 3. Update the cardholder record
    $wpdb->update(
        'ac_cardholders',
        [
            'resident_type' => $new_type,
            'household_id'  => $target_household_id,
        ],
        [ 'id' => $cardholder_id ]
    );

    // 4. Move vehicles tied to this cardholder's credentials to the target household
    $wpdb->query( $wpdb->prepare(
        "UPDATE ac_vehicles v
         JOIN ac_credentials cred ON cred.vehicle_id = v.vehicle_id
         SET v.household_id = %d
         WHERE cred.cardholder_id = %d",
        $target_household_id, $cardholder_id
    ) );

    // 5. Fire core action hook for sub-plugins/hardware sync
    do_action( 'fsbhoa_core_cardholder_household_changed', $cardholder_id, $target_household_id, $current->household_id );

    return [
        'cardholder_id' => $cardholder_id,
        'household_id'  => $target_household_id,
        'resident_type' => $new_type,
    ];
}



/**
 * Fetches current group memberships for a cardholder.
 * Moved to global scope so both Page and Action classes can use it.
 */
function get_cardholder_group_memberships($cardholder_id) {
    global $wpdb;
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT group_id FROM ac_cardholder_groups WHERE cardholder_id = %d", 
        $cardholder_id
    ));
    
    if ($wpdb->last_error) {
        return [];
    }

    $existing_groups = wp_list_pluck($results, 'group_id');
    sort($existing_groups);
    return $existing_groups;
}

/**
 * Standardizes a street address to USPS preferred abbreviations and Title Case.
 * Strips punctuation and normalizes common street/unit suffixes.
 *
 * @param string $address The raw address string.
 * @return string The standardized Title Case address.
 */
function fsbhoa_standardize_address( $address ) {
    if ( empty( $address ) ) {
        return '';
    }

    // 1. Convert to lowercase for uniform matching
    $address = strtolower( trim( $address ) );

    // 2. Strip periods and commas (USPS prefers no punctuation)
    $address = preg_replace( '/[.,]/', '', $address );

    // 3. Define dictionary mapping using regex word boundaries (\b)
    $replacements = [
        // Street Suffixes
        '\bavenue\b'    => 'ave', 
        '\bboulevard\b' => 'blvd',
        '\bcircle\b'    => 'cir',
        '\bcourt\b'     => 'ct',
        '\bdrive\b'     => 'dr',
        '\blane\b'      => 'ln',
        '\bplace\b'     => 'pl',
        '\broad\b'      => 'rd',
        '\bstreet\b'    => 'st',
        '\bparkway\b'   => 'pkwy',
        '\bhighway\b'   => 'hwy',
        '\bsquare\b'    => 'sq',
        '\bterrace\b'   => 'ter',
        '\btrail\b'     => 'trl',
        
        // Catch common non-standard abbreviations
        '\bav\b'        => 'ave',
        '\bstr\b'       => 'st',
        '\bblv\b'       => 'blvd',

        // Directionals
        '\bnorth\b'     => 'n', 
        '\bsouth\b'     => 's', 
        '\beast\b'      => 'e', 
        '\bwest\b'      => 'w',
        '\bnortheast\b' => 'ne', 
        '\bnorthwest\b' => 'nw', 
        '\bsoutheast\b' => 'se', 
        '\bsouthwest\b' => 'sw',

        // Secondary Units
        '\bapartment\b' => 'apt', 
        '\bsuite\b'     => 'ste', 
        '\bbuilding\b'  => 'bldg',
        '\broom\b'      => 'rm', 
        '\bdepartment\b'=> 'dept', 
        '\bfloor\b'     => 'fl'
    ];

    // 4. Apply the dictionary replacements
    foreach ( $replacements as $pattern => $replacement ) {
        $address = preg_replace( '/' . $pattern . '/', $replacement, $address );
    }

    // 5. Convert to Title Case
    $address = ucwords( $address );

    // 6. Fix specific Title Case anomalies (e.g., ucwords makes "Ne" instead of "NE")
    $address = preg_replace( '/\bNe\b/', 'NE', $address );
    $address = preg_replace( '/\bNw\b/', 'NW', $address );
    $address = preg_replace( '/\bSe\b/', 'SE', $address );
    $address = preg_replace( '/\bSw\b/', 'SW', $address );
    $address = preg_replace( '/\bPo Box\b/', 'PO Box', $address );

    // 7. Strip out accidental double spaces
    $address = preg_replace( '/\s+/', ' ', $address );

    return trim( $address );
}


/**
 * Sends a push notification to a Discord channel via Webhook.
 *
 * @param string $message The alert text to send.
 */
function fsbhoa_send_discord_alert( $message ) {
    // Paste your copied Discord Webhook URL here
    $webhook_url = get_option('fsbhoa_ac_discord_webhook_url', '');
    $username    = get_option('fsbhoa_ac_discord_username', 'Access Control Monitor');

    if ( empty( $webhook_url ) ) {
        return;
    }

    $payload = json_encode([
        'content'  => "@everyone 🚨 **CRITICAL ALERT** 🚨\n" . $message,
        'username' => $username
    ]);

    wp_remote_post( $webhook_url, [
        'headers'  => [ 'Content-Type' => 'application/json; charset=utf-8' ],
        'body'     => $payload,
        'blocking' => false
    ]);
}

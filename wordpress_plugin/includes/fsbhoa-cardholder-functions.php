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
            'card_status' => 'archived',
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


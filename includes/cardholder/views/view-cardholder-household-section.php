<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the Household section of the cardholder form.
 *
 * @param array $form_data    The current data for the form.
 * @param bool  $is_edit_mode Whether the form is in edit mode.
 */
function fsbhoa_render_household_section( $form_data, $is_edit_mode ) {
    ?>
    <div class="fsbhoa-form-section" style="margin-top: 5px;">
        <div class="form-row">
            <div class="form-field" style="width: 100%;">

                <!-- The Dynamic AJAX Panel (will auto-load on the Edit screen) -->
                <!-- Reduced padding from 15px to 8px to compress vertical space -->
                <div id="fsbhoa_household_panel" style="display:none; padding: 8px; background: #f0f6fc; border-left: 4px solid #72aee6; border-radius: 4px;">
                    <!-- Content injected by AJAX -->
                </div>

            </div>
        </div>
    </div>
    <?php
}


// ==============================================================================
// SAVE LOGIC: Handle Move-outs and Household Assignments
// ==============================================================================
/**
 * Processes household assignment and ghost tenant archiving on form save.
 *
 * @param array $data_to_save The sanitized cardholder data array (passed by reference).
 * @param int   $property_id  The selected property ID.
 * @param int   $item_id      The current cardholder ID (0 if new).
 * @param bool  $is_edit_mode Whether we are editing an existing record.
 *
 */
 function fsbhoa_process_household_on_save( &$data_to_save, $property_id, $item_id, $is_edit_mode ) {
    global $wpdb;

    if ( empty($property_id) || $property_id <= 0 ) {
        return;
    }

    // 1. Strictly standalone single-person types (Contractor, Staff, etc.)
    $standalone_types = ['Contractor', 'Staff', 'Other', 'Emergency', 'Delivery'];
    $resident_type    = isset($data_to_save['resident_type']) ? trim($data_to_save['resident_type']) : '';
    $exclude_sql      = $is_edit_mode ? $wpdb->prepare(" AND id != %d", $item_id) : "";

    if ( in_array($resident_type, $standalone_types) ) {
        // Standalone types always get their own individual household
        $safe_last_name = (!empty($data_to_save['last_name'])) ? $data_to_save['last_name'] : 'New';
        $household_name = sanitize_text_field( $safe_last_name . " ({$resident_type})" );
        $wpdb->insert('ac_households', ['household_name' => $household_name]);
        if ( $wpdb->insert_id ) {
            $data_to_save['household_id'] = $wpdb->insert_id;
        }
        return;
    }

    // 2. Determine target household based on whether this is a Landlord or an On-Site Resident
    $is_landlord = ( strcasecmp($resident_type, 'Landlord') === 0 );

    if ( $is_landlord ) {
        // Group ONLY with other Landlords on this property
        $existing_household_member = $wpdb->get_row($wpdb->prepare(
            "SELECT household_id FROM ac_cardholders
             WHERE property_id = %d
               AND cardholder_status IN ('active', 'inactive')
               AND resident_type = 'Landlord'
               AND household_id IS NOT NULL
               {$exclude_sql}
             LIMIT 1",
            $property_id
        ));
    } else {
        // Group ONLY with other on-site residents on this property (exclude Landlord & Standalone)
        $existing_household_member = $wpdb->get_row($wpdb->prepare(
            "SELECT household_id FROM ac_cardholders
             WHERE property_id = %d
               AND cardholder_status IN ('active', 'inactive')
               AND resident_type != 'Landlord'
               AND resident_type NOT IN ('Contractor', 'Staff', 'Other', 'Emergency', 'Delivery')
               AND household_id IS NOT NULL
               {$exclude_sql}
             LIMIT 1",
            $property_id
        ));
    }

    // 3. Assign to found household, or generate a fresh distinct household
    if ( $existing_household_member && !empty($existing_household_member->household_id) ) {
        $data_to_save['household_id'] = absint($existing_household_member->household_id);
    } else {
        $safe_last_name = (!empty($data_to_save['last_name'])) ? $data_to_save['last_name'] : 'New';
        $suffix         = $is_landlord ? " (Landlord)" : " Household";
        $household_name = sanitize_text_field( $safe_last_name . $suffix );

        $wpdb->insert('ac_households', ['household_name' => $household_name]);
        $new_household_id = $wpdb->insert_id;

        if ( $new_household_id ) {
            $data_to_save['household_id'] = $new_household_id;
        }
    }
}


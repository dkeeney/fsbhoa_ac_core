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

    // Define which types are strictly standalone (they do NOT share a household)
    $standalone_types = ['Contractor', 'Staff', 'Other', 'Emergency', 'Delivery'];
    $resident_type = isset($data_to_save['resident_type']) ? $data_to_save['resident_type'] : '';

    $existing_household_member = null;

    // Only attempt to group normal residents and landlords
    if ( !in_array($resident_type, $standalone_types) ) {
        $exclude_sql = $is_edit_mode ? $wpdb->prepare(" AND id != %d", $item_id) : "";

        // Ensure we only join a household that belongs to a NORMAL resident
        // (We don't want a new resident accidentally joining a Contractor's household)
        $existing_household_member = $wpdb->get_row($wpdb->prepare(
            "SELECT household_id FROM ac_cardholders
             WHERE property_id = %d
               AND cardholder_status IN ('active', 'inactive')
               AND resident_type NOT IN ('Contractor', 'Staff', 'Other', 'Emergency', 'Delivery')
               AND household_id IS NOT NULL
               {$exclude_sql}
             LIMIT 1",
            $property_id
        ));
    }

    if ( $existing_household_member && !empty($existing_household_member->household_id) ) {
        // Join the existing active household at this property
        $data_to_save['household_id'] = absint($existing_household_member->household_id);
    } else {
        // Create a distinct household.
        $safe_last_name = (!empty($data_to_save['last_name'])) ? $data_to_save['last_name'] : 'New';

        // If it's a standalone type, name it "Smith (Contractor)" instead of "Smith Household"
        $suffix = in_array($resident_type, $standalone_types) ? " ({$resident_type})" : " Household";
        $household_name = sanitize_text_field( $safe_last_name . $suffix );

        $wpdb->insert('ac_households', ['household_name' => $household_name]);
        $new_household_id = $wpdb->insert_id;

        if ( $new_household_id ) {
            $data_to_save['household_id'] = $new_household_id;
        }
    }
}


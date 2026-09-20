<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the Address section of the cardholder form.
 *
 * @param array $form_data The current data for the form.
 */
function fsbhoa_render_address_section( $form_data ) {
    $resident_type = isset($form_data['resident_type']) ? $form_data['resident_type'] : '';
    ?>
    <div class="fsbhoa-form-section">
        <div class="form-row">
            <!-- Property Address Field -->
            <div class="form-field is-flexible">
                 <label for="fsbhoa_property_search_input"><?php esc_html_e( 'Property Address', 'fsbhoa-ac' ); ?></label>
                <input type="text" id="fsbhoa_property_search_input" name="property_address_display" placeholder="<?php esc_attr_e( 'Start typing to search...', 'fsbhoa-ac' ); ?>" value="<?php echo esc_attr($form_data['property_address_display']); ?>">
                <input type="hidden" name="property_id" id="fsbhoa_property_id_hidden" value="<?php echo esc_attr($form_data['property_id']); ?>">
                
            </div>

            <!-- Resident Type Field -->
            <div class="form-field">
                <label for="resident_type"><?php esc_html_e( 'Resident Type', 'fsbhoa-ac' ); ?></label>
                <select name="resident_type" id="resident_type">
                    <option value="">Select Type</option>
                    <option value="Resident Owner" <?php selected($resident_type, 'Resident Owner'); ?>>Resident Owner</option>
                    <option value="Landlord" <?php selected($resident_type, 'Landlord'); ?>>Landlord</option>
                    <option value="Tenant" <?php selected($resident_type, 'Tenant'); ?>>Tenant</option>
                </select>
            </div>
            <div class="form-field fsbhoa-checkbox-field">
                <label for="manual_override">
                    <input type="checkbox" name="manual_override" id="manual_override" value="1" <?php checked(isset($form_data['origin']) && $form_data['origin'] === 'manual'); ?>>
                    <span><?php esc_html_e('Manual Override', 'fsbhoa-ac'); ?></span>
                </label>
                <p class="description"><?php esc_html_e('Prevents overwrite by imports.', 'fsbhoa-ac'); ?></p>
            </div>
        </div>
     </div>
<?php
}

/**
 * Validates Address-related data from a form submission.
 *
 * @param array $form_data The sanitized form data.
 * @return array An array of error messages.
 */
function fsbhoa_validate_address_data( $post_data ) {
    global $wpdb;
    $errors = [];
    $data   = [];

    $property_id = isset( $post_data['property_id'] ) ? absint( $post_data['property_id'] ) : 0;

    // 1. Mandatory check: property_id must be provided
    if ( ! $property_id ) {
        $errors['property_id'] = __( 'A valid Property Address from the registered community roster is required.', 'fsbhoa-ac' );
    } elseif ( 480 === $property_id ) {
        // 2. Explicit exclusion: Lodge is reserved for non-residents
        $errors['property_id'] = __( '"Lodge" is reserved for non-residents. Please manage staff and contractors via the Vendor page.', 'fsbhoa-ac' );
    } else {
        // 3. Strict DB existence check: Must exist in ac_property
        $valid_property = $wpdb->get_var( $wpdb->prepare(
            "SELECT property_id FROM ac_property WHERE property_id = %d LIMIT 1",
            $property_id
        ) );

        if ( ! $valid_property ) {
            $errors['property_id'] = __( 'The selected property is not recognized in the community property roster.', 'fsbhoa-ac' );
        } else {
            $data['property_id'] = $property_id;
        }
    }

    // Validate resident_type whitelist
    $resident_type = isset( $post_data['resident_type'] ) ? sanitize_text_field( wp_unslash( $post_data['resident_type'] ) ) : '';
    $allowed_types = [ 'Resident Owner', 'Landlord', 'Tenant' ];

    if ( empty( $resident_type ) || ! in_array( $resident_type, $allowed_types, true ) ) {
        $errors['resident_type'] = __( 'Please select a valid Resident Type (Resident Owner, Landlord, or Tenant).', 'fsbhoa-ac' );
    } else {
        $data['resident_type'] = $resident_type;
    }

    return [
        'errors' => $errors,
        'data'   => $data,
    ];
}





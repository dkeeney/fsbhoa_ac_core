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
                    <option value="Family Member" <?php selected($resident_type, 'Family Member'); ?>>Family Member</option>
                    <option value="Property Manager" <?php selected($resident_type, 'Property Manager'); ?>>Property Manager</option>
                    <option value="Contractor" <?php selected($resident_type, 'Contractor'); ?>>Contractor</option>
                    <option value="Staff" <?php selected($resident_type, 'Staff'); ?>>Staff</option>
                    <option value="Emergency" <?php selected($resident_type, 'Emergency'); ?>>Emergency</option>
                    <option value="Delivery" <?php selected($resident_type, 'Delivery'); ?>>Delivery</option>
                    <option value="Other" <?php selected($resident_type, 'Other'); ?>>Other</option>
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
    $errors = array();
    $sanitized_data = array();
    $allowed_resident_types = array(
        'Resident Owner', 'Landlord', 'Tenant', 'Family Member',
        'Property Manager', 'Staff', 'Contractor', 'Emergency', 'Delivery', 'Other'
    );

    $sanitized_data['resident_type'] = isset($post_data['resident_type']) ? sanitize_text_field(wp_unslash($post_data['resident_type'])) : '';
    $sanitized_data['property_id']   = isset($post_data['property_id']) && !empty($post_data['property_id']) ? absint($post_data['property_id']) : null;

    // Check if address text was entered without selecting a valid ID from the autocomplete.
    $property_address_display = isset($post_data['property_address_display']) ? trim(wp_unslash($post_data['property_address_display'])) : '';
    if ( !empty($property_address_display) && empty($sanitized_data['property_id']) ) {
        $errors['property_id'] = 'The address is not valid. Please select a property from the dropdown list or clear the field.';
    }
    if (empty($sanitized_data['property_id'])) {
        $errors['property_id'] = 'A valid Property Address is required.';
    }
    
    if ( empty($sanitized_data['resident_type']) || !in_array( $sanitized_data['resident_type'], $allowed_resident_types ) ) {
        $errors['resident_type'] = 'A valid Resident Type is required.';
    }
    $sanitized_data['origin'] = isset($post_data['manual_override']) ? 'manual' : 'import';

    return array( 'errors' => $errors, 'data' => $sanitized_data );
}





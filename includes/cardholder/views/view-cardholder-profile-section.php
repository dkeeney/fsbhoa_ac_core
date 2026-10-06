<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the main profile section of the cardholder form.
 *
 * @param array $form_data The current data for the form.
 */
function fsbhoa_render_profile_section( $form_data, $all_groups, $cardholder_groups, $is_edit_mode ) {
?>
<div class="fsbhoa-form-section">
    <!-- ROW 1; NAME row  -->
    <div class="form-row">
        <div class="form-field">
            <label for="first_name">First Name</label>
            <input type="text" name="first_name" id="first_name" value="<?php echo esc_attr($form_data['first_name']); ?>" required>
        </div>
        <div class="form-field">
            <label for="last_name">Last Name</label>
            <input type="text" name="last_name" id="last_name" value="<?php echo esc_attr($form_data['last_name']); ?>" required>
        </div>
        <div class="form-field" style="max-width: 200px;">
            <label for="title">Title</label>
            <input type="text" name="title" id="title" value="<?php echo esc_attr($form_data['title']); ?>">
        </div>
        <div class="form-field">
            <label>Formal Name (from import)</label>
            <span class="readonly-field">
                <?php
                $import_name = trim(esc_html($form_data['import_first_name'] . ' ' . $form_data['import_last_name']));
                echo !empty($import_name) ? $import_name : 'N/A';
                ?>
            </span>
        </div>
    </div>


    <!-- ROW 2: Contact Info -->
    <div class="form-row">
        <div class="form-field">
            <label for="email">Email</label>
            <input type="text" name="email" id="email" value="<?php echo esc_attr($form_data['email']); ?>" pattern=".+@.+\..+" title="Please enter a valid email address (e.g., name@domain.com)" style="width: 275px; font-size: 15px; font-weight: 500; color: #2c3338;">
        </div>
        <div class="form-field">
            <label for="phone">Phone Number</label>
            <input type="tel" name="phone" id="phone" value="<?php echo esc_attr($form_data['phone']); ?>" pattern="[0-9\s\(\)\-\.+]{10,}"  title="Please enter a valid 10-digit phone number."  style="width: 120px; font-size: 15px; font-weight: 500; color: #2c3338;">
        </div>
        <div class="form-field">
            <label for="phone_type">Phone Type</label>
            <select name="phone_type" id="phone_type">
                <?php 
                $pt = $form_data['phone_type'] ?? '';
                $current_phone_type = (trim($pt) === '') ? 'Mobile' : trim($pt);
                ?>
                <option value="" <?php selected($current_phone_type, ''); ?>>-- Select --</option>
                <option value="Mobile" <?php selected($current_phone_type, 'Mobile'); ?>>Mobile</option>
                <option value="Landline" <?php selected($current_phone_type, 'Landline'); ?>>Landline</option>
                <option value="Work" <?php selected($current_phone_type, 'Work'); ?>>Work</option>
                <option value="Other" <?php selected($current_phone_type, 'Other'); ?>>Other</option>
            </select>
        </div>
    </div>

    <!--  ROW 3: Permission Groups  -->
    <div class="form-row" style="padding-top: 10px; padding-bottom: 10px;">
        <div class="form-field" style="width: 100%;">
            <label style="margin-bottom: 8px; display: block;">Permissions Groups</label>
            <div class="checkbox-group-container" style="display: flex; flex-wrap: wrap; gap: 25px; align-items: center;">
                <?php if (!empty($all_groups)) : ?>
                    <?php foreach ($all_groups as $group) : ?>
                        <label style="display: flex; align-items: center; gap: 6px; font-weight: normal; margin: 0;">
                            <input type="checkbox" name="cardholder_groups[]" value="<?php echo esc_attr($group->group_id); ?>" <?php checked(in_array($group->group_id, $cardholder_groups) || (!$is_edit_mode && $group->is_default)); ?>>
                            <?php echo esc_html($group->group_name); ?>
                        </label>
                    <?php endforeach; ?>
                <?php else :  ?>
                    <p class="description"><?php _e('No groups available.', 'fsbhoa-ac'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
}


/**
 * Validates Profile-related data from a form submission.
 *
 * @param array $post_data The raw $_POST data.
 * @return array An array with 'errors' and 'data' keys.
 */
function fsbhoa_validate_profile_data( $post_data ) {
    $errors = array();
    $sanitized_data = array();

    // Sanitize all profile fields first
    $sanitized_data['first_name']    = isset($post_data['first_name']) ? sanitize_text_field(wp_unslash($post_data['first_name'])) : '';
    $sanitized_data['last_name']     = isset($post_data['last_name']) ? sanitize_text_field(wp_unslash($post_data['last_name'])) : '';
    $sanitized_data['title'] = isset($post_data['title']) ? sanitize_text_field(wp_unslash($post_data['title'])) : '';
    $raw_email = isset($post_data['email']) ? trim(wp_unslash($post_data['email'])) : '';
    $sanitized_data['email']         = isset($post_data['email']) ? sanitize_email(wp_unslash($post_data['email'])) : '';
    $sanitized_data['phone_type']    = isset($post_data['phone_type']) ? sanitize_text_field(wp_unslash($post_data['phone_type'])) : '';
    $sanitized_data['notes']         = isset($post_data['notes']) ? sanitize_textarea_field(wp_unslash($post_data['notes'])) : '';

    // --- Validation Logic ---
    if ( empty($sanitized_data['first_name']) ) { 
        $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' ); 
    }
    if ( empty($sanitized_data['last_name']) ) { 
        $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' ); 
    }
    
    // ---  EMAIL VALIDATION ---
    if ( ! empty($raw_email) ) {
        // Use our strict regex pattern to check for format like name@domain.com
        // the built-in validation does not require a top-level domain so use the regular expression.
        if ( ! preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $raw_email) ) {
            $errors['email'] = __( 'Please enter a valid email address format (e.g., name@domain.com).', 'fsbhoa-ac' );
        }
    }
    $sanitized_data['email'] = sanitize_email($raw_email);
    
    // Full phone number validation
    $raw_phone = isset($post_data['phone']) ? trim(wp_unslash($post_data['phone'])) : '';
    $sanitized_data['phone'] = $raw_phone; // Keep original for sticky field on error

    if ( ! empty($raw_phone) ) {
        // Strip all non-digit characters for validation
        $phone_digits_only = preg_replace('/[^0-9]/', '', $raw_phone);

        // If it starts with '1', remove it for the count
        if ( strlen($phone_digits_only) === 11 && substr($phone_digits_only, 0, 1) === '1' ) {
            $phone_digits_only = substr($phone_digits_only, 1);
        }

        if ( strlen($phone_digits_only) !== 10 ) {
            $errors['phone'] = __( 'Phone number must be a valid 10-digit North American number.', 'fsbhoa-ac' );
        }
        if ( empty($sanitized_data['phone_type']) ) {
            $errors['phone_type'] = __( 'Please select a phone type if a number is entered.', 'fsbhoa-ac' );
        }

        // If there are no errors, store the clean 10-digit number
        if ( ! isset($errors['phone']) ) {
            $sanitized_data['phone'] = $phone_digits_only;
        }
    }

    return array( 'errors' => $errors, 'data' => $sanitized_data );
}

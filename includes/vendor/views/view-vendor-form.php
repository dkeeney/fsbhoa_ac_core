<?php
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Renders the Add/Edit Vendor Form.
 *
 * @param int $vendor_id Cardholder ID (0 for new).
 */
function fsbhoa_render_vendor_form_view( $vendor_id = 0 ) {
    global $wpdb;

    $is_edit = ( $vendor_id > 0 );
    $form_data = array(
        'id'                => 0,
        'cardholder_type'   => 'vendor',
        'resident_type'     => 'General Contractor',
        'company'           => '',
        'household_id'      => 0,
        'first_name'        => '',
        'last_name'         => '',
        'title'             => '',
        'email'             => '',
        'phone'             => '',
        'phone_type'        => 'Mobile',
        'notes'             => '',
        'cardholder_status' => 'inactive',
        'photo_base64'      => '',
    );

    if ( $is_edit ) {
        $vendor = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM ac_cardholders WHERE id = %d AND cardholder_type = 'vendor'", $vendor_id ), ARRAY_A );
        if ( ! $vendor ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Vendor not found.', 'fsbhoa-ac' ) . '</p></div>';
            return;
        }

        $form_data = array_merge( $form_data, $vendor );

        if ( ! empty( $vendor['photo'] ) ) {
            $form_data['photo_base64'] = base64_encode( $vendor['photo'] );
        }
    }

    // Groups
    $cardholder_groups = array();
    if ( $is_edit && function_exists( 'get_cardholder_group_memberships' ) ) {
        $cardholder_groups = get_cardholder_group_memberships( $vendor_id );
    } elseif ( $is_edit ) {
        $cardholder_groups = $wpdb->get_col( $wpdb->prepare( "SELECT group_id FROM ac_cardholder_groups WHERE cardholder_id = %d", $vendor_id ) );
    }
    $all_groups = $wpdb->get_results( "SELECT group_id, group_name, is_default FROM ac_groups WHERE is_enabled = 1 ORDER BY group_name ASC" );

    // Datalist suggestions: all distinct vendor company names
    $existing_companies = $wpdb->get_col( "SELECT DISTINCT company FROM ac_cardholders WHERE cardholder_type = 'vendor' AND company IS NOT NULL AND company != '' ORDER BY company ASC" );

    $current_page_url      = remove_query_arg( array( 'action', 'id', 'cardholder_id', 'message' ) );
    $page_title            = $is_edit ? __( 'Edit Vendor / Staff', 'fsbhoa-ac' ) : __( 'Add New Vendor / Staff', 'fsbhoa-ac' );
    $submit_button_text    = $is_edit ? __( 'Update Vendor', 'fsbhoa-ac' ) : __( 'Add Vendor', 'fsbhoa-ac' );
    $form_post_hook_action = 'fsbhoa_save_vendor';
    $nonce_action          = $is_edit ? ( 'fsbhoa_save_vendor_action_' . $vendor_id ) : 'fsbhoa_save_vendor_action';
    ?>
    <div id="fsbhoa-cardholder-management-wrap" class="fsbhoa-frontend-wrap is-form-view">
        <h1><?php echo esc_html( $page_title ); ?></h1>

        <form id="fsbhoa-cardholder-form" method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo esc_attr( $form_post_hook_action ); ?>" />
            <input type="hidden" name="vendor_id" value="<?php echo esc_attr( $vendor_id ); ?>" />
            <input type="hidden" name="household_id" value="<?php echo esc_attr( $form_data['household_id'] ); ?>" />
            <input type="hidden" name="cardholder_type" value="vendor" />
            <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( $current_page_url ); ?>" />
            <?php wp_nonce_field( $nonce_action, '_wpnonce' ); ?>

            <!-- SECTION 1: Profile & Company Info -->
            <div class="fsbhoa-form-section">
                <!-- Contact Person Name & Role -->
                <div class="form-row">
                    <div class="form-field">
                        <label for="first_name"><?php esc_html_e( 'First Name', 'fsbhoa-ac' ); ?> <span style="color:red;">*</span></label>
                        <input type="text" name="first_name" id="first_name" value="<?php echo esc_attr( $form_data['first_name'] ); ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="last_name"><?php esc_html_e( 'Last Name', 'fsbhoa-ac' ); ?> <span style="color:red;">*</span></label>
                        <input type="text" name="last_name" id="last_name" value="<?php echo esc_attr( $form_data['last_name'] ); ?>" required>
                    </div>
                    <div class="form-field" style="max-width: 200px;">
                        <label for="title"><?php esc_html_e( 'Title / Role', 'fsbhoa-ac' ); ?></label>
                        <input type="text" name="title" id="title" value="<?php echo esc_attr( $form_data['title'] ); ?>" placeholder="e.g. Lead Tech, Driver">
                    </div>
                    <div class="form-field" style="min-width: 180px;">
                        <label for="resident_type"><?php esc_html_e( 'Category', 'fsbhoa-ac' ); ?> <span style="color:red;">*</span></label>
                        <select name="resident_type" id="resident_type" required>
                            <?php
                            $vendor_categories = array(
                                'Emergency'          => 'Emergency',
                                'Landscaper'         => 'Landscaper',
                                'Pool Care'          => 'Pool Care',
                                'Staff'              => 'Staff',
                                'Salon'              => 'Salon',
                                'Cafe'               => 'Cafe',
                                'Delivery'           => 'Delivery',
                                'Sanitation'         => 'Sanitation',
                                'Utilities'          => 'Utilities',
                                'Gates'              => 'Gates',
                                'General Contractor' => 'General Contractor',
                            );
                            $selected_category = ! empty( $form_data['resident_type'] ) ? $form_data['resident_type'] : 'General Contractor';
                            foreach ( $vendor_categories as $cat_val => $cat_label ) :
                                ?>
                                <option value="<?php echo esc_attr( $cat_val ); ?>" <?php selected( $selected_category, $cat_val ); ?>>
                                    <?php echo esc_html( $cat_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Company Field with Autocomplete Datalist -->
                <div class="form-row">
                    <div class="form-field" style="width: 100%;">
                        <label for="company"><?php esc_html_e( 'Company / Organization', 'fsbhoa-ac' ); ?> <span style="color:red;">*</span></label>
                        <input type="text" name="company" id="company" list="fsbhoa_vendor_company_list" value="<?php echo esc_attr( $form_data['company'] ); ?>" required autocomplete="off" placeholder="<?php esc_attr_e( 'e.g. Hall Ambulance, Landscape Pros, Spectrum...', 'fsbhoa-ac' ); ?>" style="width: 100%; font-size: 15px; font-weight: 500;">
                        <datalist id="fsbhoa_vendor_company_list">
                            <?php foreach ( $existing_companies as $comp_name ) : ?>
                                <option value="<?php echo esc_attr( $comp_name ); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <!-- Contact Details -->
                <div class="form-row">
                    <div class="form-field" style="flex: 2;">
                        <label for="email"><?php esc_html_e( 'Email', 'fsbhoa-ac' ); ?></label>
                        <input type="email" name="email" id="email" value="<?php echo esc_attr( $form_data['email'] ); ?>" style="width: 100%; font-size: 15px; font-weight: 500;">
                    </div>
                    <div class="form-field" style="flex: 1.5;">
                        <label for="phone"><?php esc_html_e( 'Phone Number', 'fsbhoa-ac' ); ?></label>
                        <input type="tel" name="phone" id="phone" value="<?php echo esc_attr( $form_data['phone'] ); ?>" style="width: 100%; font-size: 15px; font-weight: 500;">
                    </div>
                    <div class="form-field" style="flex: 1;">
                        <label for="phone_type"><?php esc_html_e( 'Phone Type', 'fsbhoa-ac' ); ?></label>
                        <select name="phone_type" id="phone_type">
                            <?php $pt = ! empty( $form_data['phone_type'] ) ? $form_data['phone_type'] : ''; ?>
                            <option value="" <?php selected( $pt, '' ); ?>><?php esc_html_e( 'None / Unspecified', 'fsbhoa-ac' ); ?></option>
                            <option value="Mobile" <?php selected( $pt, 'Mobile' ); ?>><?php esc_html_e( 'Mobile', 'fsbhoa-ac' ); ?></option>
                            <option value="Work" <?php selected( $pt, 'Work' ); ?>><?php esc_html_e( 'Work', 'fsbhoa-ac' ); ?></option>
                            <option value="Landline" <?php selected( $pt, 'Landline' ); ?>><?php esc_html_e( 'Landline', 'fsbhoa-ac' ); ?></option>
                            <option value="Other" <?php selected( $pt, 'Other' ); ?>><?php esc_html_e( 'Other', 'fsbhoa-ac' ); ?></option>
                        </select>
                    </div>
                </div>

                <!-- Permission Groups -->
                <div class="form-row" style="padding-top: 10px; padding-bottom: 10px;">
                    <div class="form-field" style="width: 100%;">
                        <label style="margin-bottom: 8px; display: block; font-weight: bold;"><?php esc_html_e( 'Permission Groups', 'fsbhoa-ac' ); ?></label>
                        <div class="checkbox-group-container" style="display: flex; flex-wrap: wrap; gap: 25px; align-items: center;">
                            <?php if ( ! empty( $all_groups ) ) : ?>
                                <?php foreach ( $all_groups as $group ) : 
                                    $is_checked = false;
                                    if ( $is_edit ) {
                                        $is_checked = in_array( $group->group_id, $cardholder_groups );
                                    } else {
                                        $is_checked = ( strcasecmp( $group->group_name, 'Staff' ) === 0 );
                                    }
                                ?>
                                    <label style="display: flex; align-items: center; gap: 6px; font-weight: normal; margin: 0;">
                                        <input type="checkbox" name="cardholder_groups[]" value="<?php echo esc_attr( $group->group_id ); ?>" <?php checked( $is_checked ); ?>>
                                        <?php echo esc_html( $group->group_name ); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <p class="description"><?php esc_html_e( 'No groups available.', 'fsbhoa-ac' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: Vehicles & Windshield Tags -->
            <?php
            $vehicles_view = FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/views/view-cardholder-vehicles-section.php';
            if ( file_exists( $vehicles_view ) ) {
                require_once $vehicles_view;
                fsbhoa_render_vehicles_section( $form_data );
            }
            ?>

            <!-- SECTION 3: Hardware Plugin Credentials (DoorKing PIN, UHPPOTE RFID) -->
            <?php
            // Sub-plugins (UHPPOTE and DoorKing) hook here to render their respective inputs
            do_action( 'fsbhoa_render_credential_fields', $form_data, $is_edit );
            ?>

            <!-- SECTION 4: Photo Section -->
            <?php
            $photo_view = FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/views/view-cardholder-photo-section.php';
            if ( file_exists( $photo_view ) ) {
                require_once $photo_view;
                fsbhoa_render_photo_section( $form_data, $is_edit, false );
            }
            ?>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo esc_html( $submit_button_text ); ?></button>
                <a href="<?php echo esc_url( $current_page_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'fsbhoa-ac' ); ?></a>
            </p>
        </form>
        <div id="fsbhoa-cropper-dialog" title="Crop Photo" style="display:none;"><div id="fsbhoa-cropper-image-container"></div></div>
    </div>
    <?php
}


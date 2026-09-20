<?php
/**
 * Controller class for Vendor & Staff front-end admin page.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/includes/vendor
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Vendor_Admin_Page {

    public function __construct() {
        add_action( 'admin_post_fsbhoa_save_vendor', array( $this, 'handle_save_vendor' ) );
        add_action( 'admin_post_fsbhoa_purge_vendor', array( $this, 'handle_purge_vendor' ) );
        add_action( 'admin_post_fsbhoa_delete_vendor', array( $this, 'handle_delete_vendor' ) );
        add_action( 'admin_post_fsbhoa_bulk_vendor_action', array( $this, 'handle_bulk_vendor_action' ) );
    }

    public function render_page() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0 );

        switch ( $action ) {
            case 'edit':
            case 'edit_vendor':
            case 'new':
            case 'add':
                $this->render_form( $id );
                break;

            case 'list':
            default:
                $this->render_list();
                break;
        }
    }

    private function render_list() {
        require_once FSBHOA_AC_PLUGIN_DIR . 'includes/vendor/views/view-vendor-list.php';
        if ( function_exists( 'fsbhoa_render_vendor_list_view' ) ) {
            fsbhoa_render_vendor_list_view();
        }
    }

    private function render_form( $id = 0 ) {
        require_once FSBHOA_AC_PLUGIN_DIR . 'includes/vendor/views/view-vendor-form.php';
        if ( function_exists( 'fsbhoa_render_vendor_form_view' ) ) {
            fsbhoa_render_vendor_form_view( $id );
        }
    }

    /**
     * Handles POST submission for creating/updating a vendor.
     */
    public function handle_save_vendor() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized user.', 'fsbhoa-ac' ) );
        }

        $vendor_id = isset( $_POST['vendor_id'] ) ? absint( $_POST['vendor_id'] ) : 0;
        $is_update = ( $vendor_id > 0 );

        $nonce_action = $is_update ? ( 'fsbhoa_save_vendor_action_' . $vendor_id ) : 'fsbhoa_save_vendor_action';
        check_admin_referer( $nonce_action, '_wpnonce' );

        global $wpdb;
        $table_name = 'ac_cardholders';

        $existing_data = array();
        if ( $is_update ) {
            $existing_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $vendor_id ), ARRAY_A );
            if ( ! $existing_data ) {
                wp_die( esc_html__( 'Vendor record not found.', 'fsbhoa-ac' ) );
            }
        }

        // 1. Sanitize Profile Data
        $company    = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
        $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
        $title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $phone_raw  = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $phone_type = isset( $_POST['phone_type'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_type'] ) ) : 'Mobile';
        $resident_type = isset( $_POST['resident_type'] ) ? sanitize_text_field( wp_unslash( $_POST['resident_type'] ) ) : 'General Contractor';
        $notes      = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

        // 2. Delegate Hardware Credential Validation to Sub-plugins (UHPPOTE, DoorKing)
        $credential_results = apply_filters(
            'fsbhoa_validate_credentials',
            array( 'errors' => array(), 'data' => array() ),
            $_POST,
            $existing_data,
            $vendor_id,
            $is_update
        );

        if ( ! empty( $credential_results['errors'] ) ) {
            wp_die( esc_html( implode( ', ', $credential_results['errors'] ) ) );
        }

        // Cardholder status is determined by sub-plugin credential validation
        $cardholder_status = $credential_results['data']['cardholder_status'] ?? 'inactive';

        // 3. Resolve 1:1 Household Container
        $cardholder_display_name = trim( $first_name . ' ' . $last_name );
        if ( empty( $cardholder_display_name ) ) {
            $cardholder_display_name = ! empty( $company ) ? $company : 'Vendor Contact';
        }
        $household_name = 'Entity: ' . $cardholder_display_name;

        $household_id = 0;
        if ( $is_update && ! empty( $existing_data['household_id'] ) ) {
            $household_id = absint( $existing_data['household_id'] );
            $wpdb->update( 'ac_households', array( 'household_name' => $household_name ), array( 'household_id' => $household_id ) );
        } else {
            $wpdb->insert( 'ac_households', array( 'household_name' => $household_name ) );
            $household_id = $wpdb->insert_id;
        }

        // 4. Photo Processing
        $photo_data = null;
        if ( ! empty( $_POST['photo_base64'] ) ) {
            $base64_clean = preg_replace( '/^data:image\/(png|jpeg);base64,/', '', $_POST['photo_base64'] );
            $photo_data   = base64_decode( $base64_clean, true );
        } elseif ( $is_update && ! empty( $existing_data['photo'] ) ) {
            $photo_data = $existing_data['photo'];
        }

        // 5. Permission Groups
        $submitted_groups = isset( $_POST['cardholder_groups'] ) ? array_map( 'absint', $_POST['cardholder_groups'] ) : array();
        sort( $submitted_groups );
        $groups_csv = implode( ',', $submitted_groups );

        $data_to_save = array(
            'cardholder_type'   => 'vendor',
            'household_id'      => $household_id,
            'company'           => $company,
            'first_name'        => $first_name,
            'last_name'         => $last_name,
            'title'             => $title,
            'email'             => $email,
            'phone'             => $phone_raw,
            'phone_type'        => $phone_type,
            'resident_type'     => $resident_type,
            'notes'             => $notes,
            'cardholder_status' => $cardholder_status,
            'groups_csv'        => $groups_csv,
            'photo'             => $photo_data,
            'updated_at'        => current_time( 'mysql' ),
        );

        if ( $is_update ) {
            $wpdb->update( $table_name, $data_to_save, array( 'id' => $vendor_id ) );
            $message = 'vendor_updated';
        } else {
            $data_to_save['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table_name, $data_to_save );
            $vendor_id = $wpdb->insert_id;
            $message   = 'vendor_added';
        }

        // 6. Save Permission Groups Mapping
        $wpdb->delete( 'ac_cardholder_groups', array( 'cardholder_id' => $vendor_id ) );
        foreach ( $submitted_groups as $g_id ) {
            $wpdb->insert( 'ac_cardholder_groups', array(
                'cardholder_id' => $vendor_id,
                'group_id'      => $g_id,
            ), array( '%d', '%d' ) );
        }

        // 7. Save Vehicles (DoorKing hook fsbhoa_core_vehicle_saved fires inside here)
        require_once FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/views/view-cardholder-vehicles-section.php';
        $vehicle_results  = fsbhoa_validate_vehicles_data( $_POST );
        $vehicles_to_save = $vehicle_results['data']['vehicle_rows'] ?? array();

        if ( ! empty( $vehicles_to_save ) ) {
            require_once FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/class-fsbhoa-cardholder-actions.php';
            $actions_controller = new Fsbhoa_Cardholder_Actions();
            $actions_controller->fsbhoa_process_vehicles_on_save( $household_id, $vendor_id, $vehicles_to_save );
        }

        // 8. Broadcast to Sub-Plugins (UHPPOTE saves MIFARE_BADGE and logs to ac_pending_changes)
        if ( $is_update ) {
            do_action( 'fsbhoa_core_cardholder_updated', $vendor_id, $data_to_save, $existing_data );
        } else {
            do_action( 'fsbhoa_core_cardholder_created', $vendor_id, $data_to_save );
        }

        // Redirect: If "Print ID" was clicked, route directly to the Zebra card printer page
        if ( isset( $_POST['fsbhoa_after_save_action'] ) && 'print' === $_POST['fsbhoa_after_save_action'] ) {
            $print_page = get_page_by_path( 'print-photo-id' );
            if ( $print_page ) {
                $print_url = add_query_arg( array(
                    'action'        => 'print_card',
                    'cardholder_id' => $vendor_id,
                ), get_permalink( $print_page->ID ) );
                wp_safe_redirect( $print_url );
                exit;
            }
        }

        // Standard save redirect back to the vendor list
        $list_page_url = remove_query_arg( array( 'action', 'id', 'cardholder_id', 'message' ), wp_get_referer() ? wp_get_referer() : home_url( '/vendors/' ) );
        wp_safe_redirect( add_query_arg( array( 'message' => $message, 'highlight' => $vendor_id ), $list_page_url ) );
        exit;
    }

    /**
     * purge a single vendor, their credentials, household, and vehicles.
     */
    public function handle_purge_vendor() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'fsbhoa-ac' ) );
        }

        $vendor_id = isset( $_GET['vendor_id'] ) ? absint( $_GET['vendor_id'] ) : 0;
        check_admin_referer( 'fsbhoa_purge_vendor_nonce_' . $vendor_id );

        $this->purge_vendor( $vendor_id );

        $redirect_url = remove_query_arg( array( 'action', 'id', 'vendor_id', '_wpnonce' ), wp_get_referer() ?: home_url( '/vendors/' ) );
        wp_safe_redirect( add_query_arg( 'message', 'vendor_purged', $redirect_url ) );
        exit;
    }
    /**
     * Permanently delete a single vendor, their credentials, household, and vehicles.
     */
    public function handle_delete_vendor() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'fsbhoa-ac' ) );
        }

        $vendor_id = isset( $_GET['vendor_id'] ) ? absint( $_GET['vendor_id'] ) : 0;
        check_admin_referer( 'fsbhoa_delete_vendor_nonce_' . $vendor_id );

        $this->delete_vendor_permanently( $vendor_id );

        $redirect_url = remove_query_arg( array( 'action', 'id', 'vendor_id', '_wpnonce' ), wp_get_referer() ?: home_url( '/vendors/' ) );
        wp_safe_redirect( add_query_arg( 'message', 'vendor_deleted', $redirect_url ) );
        exit;
    }

    /**
     * Handles bulk delete for vendors.
     */
    public function handle_bulk_vendor_action() {
        error_log( '[BULK ACTION DEBUG] POST data: ' . print_r( $_POST, true ) );
        check_admin_referer( 'fsbhoa_bulk_vendor_nonce', '_wpnonce' );

        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '-1';
        if ( $bulk_action === '-1' || empty( $_POST['cardholder_ids'] ) || ! is_array( $_POST['cardholder_ids'] ) ) {
            wp_safe_redirect( wp_get_referer() ?: home_url( '/vendors/' ) );
            exit;
        }

        $vendor_ids = array_map( 'absint', $_POST['cardholder_ids'] );


        if ( 'delete' === $bulk_action ) {
            $deleted_count = 0;
            foreach ( $vendor_ids as $id ) {
                if ( $this->delete_vendor_permanently( $id ) ) {
                    $deleted_count++;
                }
            }
            $referer = wp_get_referer() ?: home_url( '/vendor/' );
            $redirect_url = remove_query_arg( array( 'action', 'id', '_wpnonce', 'message' ), $referer );
            wp_safe_redirect( add_query_arg( array( 'message' => 'bulk_deleted', 'deleted_count' => $deleted_count ), $redirect_url ) );
            exit;

        } else if ( 'purge' === $bulk_action ) {
            $purged_count = 0;
            foreach ( $vendor_ids as $id ) {
                if ( $this->purge_vendor( $id ) ) {
                    $purged_count++;
                }
            }
            $referer = wp_get_referer() ?: home_url( '/vendor/' );
            $redirect_url = remove_query_arg( array( 'action', 'id', '_wpnonce', 'message' ), $referer );
            wp_safe_redirect( add_query_arg( array( 'message' => 'bulk_purged', 'purged_count' => $purged_count ), $redirect_url ) );
            exit;
        }
        wp_safe_redirect( wp_get_referer() ?: home_url( '/vendors/' ) );
        exit;
    }


    public function purge_vendor( $cardholder_id ) {
        global $wpdb;
        $cardholder_id = absint( $cardholder_id );

        // 1. Mark credentials archived so they can't open gates
        $wpdb->update(
            'ac_credentials',
            [ 'status' => 'archived' ],
            [ 'cardholder_id' => $cardholder_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // 2. Strip active group memberships to revoke hardware permissions immediately
        $wpdb->delete( 'ac_cardholder_groups', [ 'cardholder_id' => $cardholder_id ], [ '%d' ] );

        // 3. Mark cardholder as purged (preserves audit log history)
        $wpdb->update(
            'ac_cardholders',
            [
                'cardholder_status' => 'purged',
                'deleted_at'        => current_time( 'mysql', 1 ),
            ],
            [ 'id' => $cardholder_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        // 4. Log pending hardware change to sync panels
        if ( function_exists( 'fsbhoa_log_pending_change' ) ) {
            fsbhoa_log_pending_change( 'cardholder', $cardholder_id );
        }

        return true;
    }
    /**
     * Complete hard deletion helper.
     */
    private function delete_vendor_permanently( $vendor_id ) {
        global $wpdb;

        $vendor = $wpdb->get_row( $wpdb->prepare( "SELECT id, household_id FROM ac_cardholders WHERE id = %d AND cardholder_type = 'vendor'", $vendor_id ) );
        if ( ! $vendor ) {
            return false;
        }

        // Purge credentials and group associations
        $wpdb->delete( 'ac_credentials', array( 'cardholder_id' => $vendor_id ) );
        $wpdb->delete( 'ac_cardholder_groups', array( 'cardholder_id' => $vendor_id ) );

        // Purge private household and all associated fleet vehicles
        if ( ! empty( $vendor->household_id ) ) {
            $vehicles = $wpdb->get_col( $wpdb->prepare( "SELECT vehicle_id FROM ac_vehicles WHERE household_id = %d", $vendor->household_id ) );
            foreach ( $vehicles as $v_id ) {
                $wpdb->delete( 'ac_credentials', array( 'vehicle_id' => $v_id ) );
                do_action( 'fsbhoa_core_vehicle_deleted', $v_id, $vendor_id );
            }
            $wpdb->delete( 'ac_vehicles', array( 'household_id' => $vendor->household_id ) );
            $wpdb->delete( 'ac_households', array( 'household_id' => $vendor->household_id ) );
        }

        // Purge the cardholder row itself
        $wpdb->delete( 'ac_cardholders', array( 'id' => $vendor_id ) );

        // Tell sub-plugins cardholder was removed
        if ( function_exists( 'fsbhoa_log_pending_change' ) ) {
            fsbhoa_log_pending_change( 'cardholder', $vendor_id, json_encode( array( 'action' => 'delete' ) ) );
        }

        return true;
    }
}


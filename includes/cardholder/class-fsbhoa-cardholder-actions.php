<?php
/**
 * Handles all AJAX and admin-post actions for Cardholder management.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}


class Fsbhoa_Cardholder_Actions {

    public function __construct() {
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'ajax_search_properties_callback'));
        add_action('admin_post_fsbhoa_delete_cardholder', array($this, 'handle_delete_cardholder_action'));
        add_action('admin_post_fsbhoa_do_add_cardholder', array($this, 'handle_add_or_update_cardholder'));
        add_action('admin_post_fsbhoa_do_update_cardholder', array($this, 'handle_add_or_update_cardholder'));
        add_action('admin_post_fsbhoa_export_selected', array($this, 'handle_export_selected'));
        add_action('admin_post_fsbhoa_print_report', array($this, 'handle_print_report'));
        add_action('wp_ajax_fsbhoa_search_cardholders', array($this, 'ajax_search_cardholders_callback'));
        add_action('admin_post_fsbhoa_get_cardholder_photo', array($this, 'handle_get_cardholder_photo'));
        add_action('admin_post_fsbhoa_bulk_cardholder_action', array($this, 'handle_bulk_cardholder_action'));
        add_action('wp_ajax_fsbhoa_check_property_occupants', array($this, 'ajax_check_property_occupants'));
        add_action('wp_ajax_fsbhoa_ajax_archive_cardholder', array($this, 'ajax_archive_cardholder_callback'));
        add_action('wp_ajax_fsbhoa_ajax_restore_cardholder', array($this, 'ajax_restore_cardholder_callback'));
        add_action('wp_ajax_fsbhoa_ajax_save_cardholder', array($this, 'ajax_save_cardholder_callback'));
        add_action('wp_ajax_fsbhoa_ajax_merge_cardholders', array($this, 'ajax_merge_cardholders_callback'));
        add_action( 'wp_ajax_fsbhoa_ajax_change_resident_type', [ $this, 'ajax_change_resident_type_callback' ] );
        add_action( 'wp_ajax_fsbhoa_copy_household_debug', [ $this, 'ajax_copy_household_debug_callback' ] );
    }

    public function ajax_search_properties_callback() {
        check_ajax_referer('fsbhoa_property_search_nonce', 'security');
        global $wpdb;
        $table_name = 'ac_property';
        $search_term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $results = array();
        if (strlen($search_term) >= 1) {
            $wildcard_search_term = '%' . $wpdb->esc_like($search_term) . '%';
            $properties = $wpdb->get_results( $wpdb->prepare(
                "SELECT property_id, street_address
                 FROM {$table_name}
                 WHERE street_address LIKE %s
                   AND property_id != 480
                 ORDER BY street_address ASC
                 LIMIT 20",
                $wildcard_search_term
            ), ARRAY_A );

            if ( $wpdb->last_error ) {
                error_log('FSBHOA AJAX Property Search DB Error: ' . $wpdb->last_error);
                wp_send_json_error(array('message' => 'Database query failed.'));
                return;
            }

            if ($properties) {
                foreach ($properties as $property) {
                    $results[] = array( 'id' => $property['property_id'], 'label' => $property['street_address'], 'value' => $property['street_address'] );
                }
            }
        }
        wp_send_json_success($results);
    }



    public function ajax_check_property_occupants() {
		check_ajax_referer( 'fsbhoa_property_search_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$property_id = isset( $_POST['property_id'] ) ? absint( $_POST['property_id'] ) : 0;

		if ( $property_id === 0 ) {
			wp_send_json_error( 'Invalid property ID.' );
		}

		global $wpdb;

		// 1. Fetch all occupants for this property
		$occupants = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, first_name, last_name, cardholder_status, household_id, resident_type, origin
			 FROM ac_cardholders
			 WHERE property_id = %d
               AND cardholder_type = 'resident'
			   AND cardholder_status IN ('active', 'inactive', 'archived')
			 ORDER BY cardholder_status ASC, first_name ASC",
			$property_id
		) );

        // 2. Identify the active household ID based on target resident role
        $current_cardholder_id = isset( $_POST['cardholder_id'] ) ? absint( $_POST['cardholder_id'] ) : 0;
        $current_resident_type = isset( $_POST['resident_type'] ) ? sanitize_text_field( wp_unslash( $_POST['resident_type'] ) ) : '';
        $is_landlord           = ( strcasecmp( $current_resident_type, 'Landlord' ) === 0 );

        $household_id = 0;

        // If editing an existing cardholder who already has a household, honor it directly
        if ( $current_cardholder_id > 0 ) {
            $household_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT household_id FROM ac_cardholders WHERE id = %d",
                $current_cardholder_id
            ) );
        }

        // If creating new or unassigned, find the matching household group at this property
        if ( empty( $household_id ) ) {
            foreach ( $occupants as $occ ) {
                if ( $occ->cardholder_status === 'archived' || empty( $occ->household_id ) ) {
                    continue;
                }
                $occ_is_landlord = ( strcasecmp( $occ->resident_type, 'Landlord' ) === 0 );

                // Match Landlord to Landlord, Resident to Resident
                if ( $is_landlord === $occ_is_landlord ) {
                    $household_id = absint( $occ->household_id );
                    break;
                }
            }
        }

		// Otherwise, fall back to the active resident household at this property
		if ( empty( $household_id ) ) {
			foreach ( $occupants as $occ ) {
				if ( $occ->cardholder_status !== 'archived' && strcasecmp( $occ->resident_type, 'Landlord' ) !== 0 && ! empty( $occ->household_id ) ) {
					$household_id = absint( $occ->household_id );
					break;
				}
			}
		}

		// 3. Render the vehicle section via buffer (fires all hardware plugin hooks naturally)
		$view_file = FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/views/view-cardholder-vehicles-section.php';
		if ( file_exists( $view_file ) ) {
			require_once $view_file;
		}

		ob_start();
		fsbhoa_render_vehicles_section( [
			'id'           => $current_cardholder_id,
			'household_id' => $household_id,
		] );
        $vehicles_html = ob_get_clean();


		wp_send_json_success( [
			'occupants'     => $occupants,
			'vehicles_html' => $vehicles_html,
			'household_id'  => $household_id,
		] );
	}

    public function ajax_archive_cardholder_callback() {
        // We can safely reuse the property search nonce for this backend action
        check_ajax_referer('fsbhoa_property_search_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        $cardholder_id = isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0;
        if (!$cardholder_id) {
            wp_send_json_error('Invalid ID.');
        }

        global $wpdb;

        // Fetch credentials before archiving to log them
        $creds = $wpdb->get_col($wpdb->prepare("SELECT credential_value FROM ac_credentials WHERE cardholder_id = %d AND status = 'active'", $cardholder_id));
        $credential_list = empty($creds) ? '' : implode(',', $creds);

        // Run your core archive function
        $result = fsbhoa_archive_and_delete_cardholder( $cardholder_id );

        if ( is_wp_error($result) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Log it for the hardware sync
        $log_data = json_encode(['credentials' => $credential_list, 'action' => 'ajax_household_moveout_archive']);
        fsbhoa_log_pending_change('cardholder', $cardholder_id, $log_data);

        wp_send_json_success('Archived successfully.');
    }

    public function ajax_restore_cardholder_callback() {
        check_ajax_referer( 'fsbhoa_property_search_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $cardholder_id = isset( $_POST['cardholder_id'] ) ? absint( $_POST['cardholder_id'] ) : 0;
        if ( ! $cardholder_id ) {
            wp_send_json_error( 'Invalid ID.' );
        }

        require_once plugin_dir_path( dirname( __DIR__ ) ) . 'includes/fsbhoa-cardholder-functions.php';

        $result = fsbhoa_restore_cardholder( $cardholder_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( 'Restored successfully.' );
    }

    // This is not really a delete, just archive.
    public function handle_delete_cardholder_action() {
        global $wpdb;
        if ( ! isset($_GET['cardholder_id']) || ! is_numeric($_GET['cardholder_id']) ) {
            wp_die( esc_html__( 'Invalid cardholder ID specified.', 'fsbhoa-ac' ), esc_html__( 'Error', 'fsbhoa-ac' ), array( 'response' => 400, 'back_link' => true ) );
        }

        $item_id_to_delete = absint( $_GET['cardholder_id'] );
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';


        if ( ! wp_verify_nonce( $nonce, 'fsbhoa_delete_cardholder_nonce_' . $item_id_to_delete ) ) {
            wp_die( esc_html__( 'Security check failed. Could not archive cardholder.', 'fsbhoa-ac' ), esc_html__( 'Error', 'fsbhoa-ac' ), array( 'response' => 403, 'back_link' => true ) );
        }

        $creds = $wpdb->get_col($wpdb->prepare("SELECT credential_value FROM ac_credentials WHERE cardholder_id = %d AND status = 'active'", $item_id_to_delete));
        $credential_list = empty($creds) ? '' : implode(',', $creds);

        $result = fsbhoa_archive_and_delete_cardholder( $item_id_to_delete );

        if ( is_wp_error( $result ) ) {
            $error_string = $result->get_error_message();
            $redirect_url = add_query_arg( array( 'message' => 'cardholder_archive_error', 'error' => urlencode($error_string) ), wp_get_referer() );
        } else {
            // Log generically so hardware plugins know what was deactivated
            $log_data = json_encode(['credentials' => $credential_list, 'action' => 'archive']);
            fsbhoa_log_pending_change('cardholder', $item_id_to_delete, $log_data );

            // Redirect to the Archived Cardholders page on success
            $page_object = get_page_by_path('archived-cardholders');
            $redirect_url = $page_object ? get_permalink($page_object->ID) : home_url('/');
            $redirect_url = add_query_arg( array( 'message' => 'cardholder_archived_successfully' ), $redirect_url );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function ajax_change_resident_type_callback() {
        check_ajax_referer( 'fsbhoa_property_search_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $cardholder_id = isset( $_POST['cardholder_id'] ) ? absint( $_POST['cardholder_id'] ) : 0;
        $new_type      = isset( $_POST['new_type'] ) ? sanitize_text_field( wp_unslash( $_POST['new_type'] ) ) : '';

        require_once plugin_dir_path( dirname( __DIR__ ) ) . 'includes/fsbhoa-cardholder-functions.php';

        $result = fsbhoa_change_cardholder_resident_type( $cardholder_id, $new_type );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
    
        // Render updated vehicles buffer
        $view_file = FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/views/view-cardholder-vehicles-section.php';
        if ( file_exists( $view_file ) ) {
            require_once $view_file;
        }
    
        ob_start();
        fsbhoa_render_vehicles_section( [
            'id'           => $cardholder_id,
            'household_id' => $result['household_id'],
        ] );
        $vehicles_html = ob_get_clean();
    
        wp_send_json_success( [
            'household_id'  => $result['household_id'],
            'vehicles_html' => $vehicles_html,
            'message'       => 'Resident type and household updated successfully.',
        ] );
    }

    public function ajax_save_cardholder_callback() {
        check_ajax_referer('fsbhoa_property_search_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['errors' => ['Permission denied.']]);
        }

        $is_update = isset($_POST['cardholder_id']) && absint($_POST['cardholder_id']) > 0;
        $item_id = $is_update ? absint($_POST['cardholder_id']) : 0;

        // Pass to the shared processor
        $result = $this->execute_cardholder_save_logic( $_POST, $_FILES, $is_update, $item_id );

        if ( ! $result['success'] ) {
            wp_send_json_error(['errors' => $result['errors']]);
        }

        if ( $result['sync_needed'] ) {
            fsbhoa_log_pending_change('cardholder', $result['item_id']);
        }

        wp_send_json_success(['cardholder_id' => $result['item_id'], 'message' => 'Saved successfully.']);
    }

    public function handle_add_or_update_cardholder() {
        $is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_do_update_cardholder' );
        $item_id = $is_update ? (isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0) : 0;

        $nonce_action = $is_update ? 'fsbhoa_update_cardholder_action_' . $item_id : 'fsbhoa_add_cardholder_action';
        check_admin_referer($nonce_action, '_wpnonce');

        $form_page_url = wp_get_referer() ? wp_get_referer() : home_url('/');
        $list_page_url = remove_query_arg( array('action', 'cardholder_id', 'message'), $form_page_url );

        // Pass to the shared processor
        $result = $this->execute_cardholder_save_logic( $_POST, $_FILES, $is_update, $item_id );

        if ( ! $result['success'] ) {
            $user_id = get_current_user_id();
            $transient_key = 'fsbhoa_form_feedback_' . ($is_update ? 'edit_' . $item_id . '_' : 'add_') . $user_id;
            set_transient($transient_key, array('errors' => $result['errors'], 'data' => wp_unslash($_POST)), MINUTE_IN_SECONDS * 5);
            wp_redirect( add_query_arg( array('message' => 'validation_error'), $form_page_url ) );
            exit;
        }

        if ( $result['sync_needed'] ) {
            fsbhoa_log_pending_change('cardholder', $result['item_id']);
        }

        $message_code = $is_update ? 'cardholder_updated' : 'cardholder_added';

        if ( isset($_POST['fsbhoa_after_save_action']) && $_POST['fsbhoa_after_save_action'] === 'print' ) {
            $print_page = get_page_by_path('print-photo-id');
            if ($print_page) {
                $print_url = add_query_arg('cardholder_id', $result['item_id'], get_permalink($print_page->ID));
                wp_redirect($print_url);
            } else {
                wp_redirect( admin_url('admin.php?page=fsbhoa_cardholder') );
            }
        } else {
            wp_redirect( add_query_arg( array('message' => $message_code, 'highlight' => $result['item_id']), $list_page_url ) );
        }
        exit;
    }

    /**
     * Core business logic to validate, save, and trigger hardware syncs for a cardholder.
     * Shared by both AJAX and traditional form submissions.
     *
     * @return array Contains 'success' (bool), 'item_id' (int), 'errors' (array), and 'sync_needed' (bool).
     */
    private function execute_cardholder_save_logic( $post_data, $file_data, $is_update, $item_id ) {
        global $wpdb;
        $table_name = 'ac_cardholders';

        // Load Validators
        $view_path = FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/views/';
        require_once $view_path . 'view-cardholder-profile-section.php';
        require_once $view_path . 'view-cardholder-address-section.php';
        require_once $view_path . 'view-cardholder-vehicles-section.php';
        require_once $view_path . 'view-cardholder-photo-section.php';
        require_once $view_path . 'view-cardholder-household-section.php';

        $existing_data = array();
        if ($is_update) {
            $existing_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $item_id), ARRAY_A);
            if (!$existing_data) {
                return array('success' => false, 'errors' => ['Cardholder not found.'], 'item_id' => 0, 'sync_needed' => false);
            }
            $existing_groups = get_cardholder_group_memberships($item_id);
            $existing_data['groups_csv'] = implode(',', $existing_groups);
        }

        // 1. Validation Phase
        $profile_results = fsbhoa_validate_profile_data($post_data);
        $address_results = fsbhoa_validate_address_data($post_data);
        $photo_results   = fsbhoa_validate_photo_data($post_data, $file_data);
        $vehicle_results = fsbhoa_validate_vehicles_data($post_data);
        $credential_results = apply_filters('fsbhoa_validate_credentials', ['errors' => [], 'data' => []], $post_data, $existing_data, $item_id, $is_update);

        // 2. Merge Errors
        $errors = array_merge($profile_results['errors'], $address_results['errors'], $photo_results['errors'], $vehicle_results['errors'], $credential_results['errors']);

        // 3. Separate Cardholder Data from Vehicle Data
        $data_to_save = array_merge($existing_data, $profile_results['data'], $address_results['data'], $photo_results['data'], $credential_results['data']);
        $data_to_save['cardholder_type'] = 'resident';

        $vehicles_to_save = $vehicle_results['data']['vehicle_rows'] ?? [];

        // Process Household constraints
        $property_id = isset($data_to_save['property_id']) ? absint($data_to_save['property_id']) : 0;
        fsbhoa_process_household_on_save( $data_to_save, $property_id, $item_id, $is_update );

        // Hard halt if validation failed
        if (!empty($errors)) {
            return array('success' => false, 'errors' => array_values($errors), 'item_id' => $item_id, 'sync_needed' => false);
        }

        $submitted_groups = isset($post_data['cardholder_groups']) ? (array) array_map('absint', $post_data['cardholder_groups']) : [];
        sort($submitted_groups);
        $data_to_save['groups_csv'] = implode(',', $submitted_groups);

        $sync_needed = false;

        // DB Operations
        if ($is_update) {
            if ($data_to_save['cardholder_status'] !== $existing_data['cardholder_status'] ||
                $data_to_save['groups_csv'] !== $existing_data['groups_csv']) {
                $sync_needed = true;
            }

            if ( (string)$data_to_save['email'] !== (string)$existing_data['email'] ) {
                if ( ! wp_next_scheduled( 'fsbhoa_instant_web_sync_event' ) ) {
                    wp_schedule_single_event( time() + 5, 'fsbhoa_instant_web_sync_event' );
                }
            }

            $result = $wpdb->update($table_name, $data_to_save, array('id' => $item_id));
            if ($result === false) {
                return array('success' => false, 'errors' => ['Database error while updating: ' . $wpdb->last_error], 'item_id' => $item_id, 'sync_needed' => false);
            }
        } else {
            $result = $wpdb->insert($table_name, $data_to_save);
            if ($result === false) {
                return array('success' => false, 'errors' => ['Database error while adding: ' . $wpdb->last_error], 'item_id' => 0, 'sync_needed' => false);
            }
            $item_id = $wpdb->insert_id;

            if ( ! empty( $data_to_save['email'] ) ) {
                if ( ! wp_next_scheduled( 'fsbhoa_instant_web_sync_event' ) ) {
                    wp_schedule_single_event( time() + 5, 'fsbhoa_instant_web_sync_event' );
                }
            }
        }

        // Apply dependencies
        $this->save_cardholder_groups($item_id, $submitted_groups);

        // 4. Trigger Vehicle Save AFTER the Cardholder has an ID and a Household ID
        if ( !empty($data_to_save['household_id']) && !empty($vehicles_to_save) ) {
            $this->fsbhoa_process_vehicles_on_save($data_to_save['household_id'], $item_id, $vehicles_to_save);
        }

        // 5. Broadcast to sub-plugins
        if ($is_update) {
            do_action('fsbhoa_core_cardholder_updated', $item_id, $data_to_save, $existing_data);
        } else {
            do_action('fsbhoa_core_cardholder_created', $item_id, $data_to_save);
        }

        return array('success' => true, 'item_id' => $item_id, 'sync_needed' => $sync_needed, 'errors' => []);
    }


    /**
     * Executes DB operations for the validated vehicle array and broadcasts hardware hooks.
     */
    public function fsbhoa_process_vehicles_on_save( $household_id, $cardholder_id, $vehicles_to_save ) {
        global $wpdb;

        foreach ( $vehicles_to_save as $v ) {
            $v_id = $v['vehicle_id'];

            // Handle deletions
            if ( !empty($v['delete']) ) {
                $wpdb->delete('ac_vehicles', ['vehicle_id' => $v_id]);
                do_action('fsbhoa_core_vehicle_deleted', $v_id, $cardholder_id);
                continue;
            }

            $data = [
                'household_id'  => $household_id,
                'vehicle_type'  => $v['vehicle_type'],
                'make'          => $v['make'],
                'model'         => $v['model'],
                'year'          => $v['year'],
                'license_plate' => $v['license_plate'],
                'plate_state'   => $v['plate_state']
            ];

            if ( $v_id > 0 ) {
                $wpdb->update('ac_vehicles', $data, ['vehicle_id' => $v_id]);
            } else {
                $wpdb->insert('ac_vehicles', $data);
                $v_id = $wpdb->insert_id;
            }

            // Broadcast to hardware plugins (DoorKing, LPR) so they can parse their injected fields
            do_action('fsbhoa_core_vehicle_saved', $v_id, $v['raw_row'], $cardholder_id);
        }
    }

    private function save_cardholder_groups($cardholder_id, $group_ids) {
        global $wpdb;
        $group_ids = array_map('absint', $group_ids);
        $wpdb->delete('ac_cardholder_groups', ['cardholder_id' => $cardholder_id]);
        if ($wpdb->last_error) {
            error_log('FSBHOA DB Error: Failed to delete old groups for cardholder ' . $cardholder_id . ': ' . $wpdb->last_error);
            return;
        }
        if (!empty($group_ids)) {
            foreach ($group_ids as $group_id) {
                $wpdb->insert('ac_cardholder_groups',
                    ['cardholder_id' => $cardholder_id, 'group_id' => $group_id,],
                    ['%d', '%d']
                );
                if ($wpdb->last_error) {
                    error_log('FSBHOA DB Error: Failed to insert group ' . $group_id . ' for cardholder ' . $cardholder_id . ': ' . $wpdb->last_error);
                }
            }
        }
    }



    public function handle_export_selected() {
        if ( empty( $_POST['cardholder'] ) ) { return; }
        $nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '';
        if ( ! wp_verify_nonce($nonce, 'fsbhoa_export_nonce') ) {
            wp_die( 'Security check failed.' );
        }
        $cardholder_ids = array_map('absint', $_POST['cardholder']);
        if ( empty( $cardholder_ids ) ) { return; }

        global $wpdb;
        $ids_string = implode( ',', $cardholder_ids );
        $sql = "SELECT c.*, p.street_address
                FROM ac_cardholders c
                LEFT JOIN ac_property p ON c.property_id = p.property_id
                WHERE c.id IN ($ids_string)";

        $items_to_export = $wpdb->get_results( $sql, ARRAY_A );

        if ($wpdb->last_error) {
            wp_die('Database error while fetching cardholders for export: ' . esc_html($wpdb->last_error));
        }
        if ( empty( $items_to_export ) ) { return; }

        $default_group_names = $wpdb->get_col("SELECT group_name FROM ac_groups WHERE is_default = 1");

        foreach ($items_to_export as $key => $item) {
            $groups_query = $wpdb->prepare("SELECT g.group_name FROM ac_groups g JOIN ac_cardholder_groups cg ON g.group_id = cg.group_id WHERE cg.cardholder_id = %d", $item['id']);
            $explicit_group_names = $wpdb->get_col($groups_query);
            $all_group_names = array_merge($default_group_names, $explicit_group_names);
            $items_to_export[$key]['groups'] = implode(', ', array_unique($all_group_names));
            $photo_url = add_query_arg(['action' => 'fsbhoa_get_cardholder_photo', 'id' => $item['id']], admin_url('admin-post.php'));
            $items_to_export[$key]['photo_url'] = $photo_url;
        }

        $filename = 'cardholder-export-' . date('Y-m-d') . '.csv';
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        $output = fopen( 'php://output', 'w' );
        $headers = array_keys( $items_to_export[0] );
        $photo_key = array_search('photo', $headers);
        if ($photo_key !== false) { unset($headers[$photo_key]); }
        fputcsv( $output, $headers );
        foreach ( $items_to_export as $item ) {
            if ( isset( $item['photo'] ) ) { unset( $item['photo'] ); }
            fputcsv( $output, $item );
        }
        fclose( $output );
        exit();
    }

    public function handle_print_report() {
        check_admin_referer('fsbhoa_print_report_nonce');
        if (empty($_POST['cardholder_ids']) || !is_array($_POST['cardholder_ids'])) {
            wp_die('Error: No cardholders were selected.');
        }
        $cardholder_ids = array_map('absint', $_POST['cardholder_ids']);
        $orderby_col = isset($_POST['orderby_col']) ? absint($_POST['orderby_col']) : 2;
        $order_dir = isset($_POST['order_dir']) && in_array(strtolower($_POST['order_dir']), ['asc', 'desc']) ? strtolower($_POST['order_dir']) : 'asc';

        $print_page_url = get_permalink(get_page_by_path('cardholder-pages'));
        if (!$print_page_url) {
            wp_die('Configuration Error: The "Cardholder Pages" page has not been created.');
        }

        $url_with_params = add_query_arg(array(
            'selected_ids' => implode(',', $cardholder_ids),
            'orderby_col'  => $orderby_col,
            'order_dir'    => $order_dir
        ), $print_page_url);

        wp_safe_redirect($url_with_params);
        exit;
    }

    /**
     * AJAX callback to search for live cardholders by name or address.
     * REFACTORED to exclude archived/purged users.
     */
    public function ajax_search_cardholders_callback() {
        check_ajax_referer('fsbhoa_cardholder_search_nonce', 'security');

        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        if (strlen($term) < 2) {
            wp_send_json_success([]);
            return;
        }

        global $wpdb;
        $cardholders_table = 'ac_cardholders';
        $properties_table = 'ac_property';
        $wildcard_term = '%' . $wpdb->esc_like($term) . '%';

        // UPDATED QUERY: Added a WHERE clause to ensure we only search for active,
        // inactive, or disabled users, excluding archived and purged ones.
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.first_name, c.last_name, p.street_address,
                    GROUP_CONCAT(cred.credential_value SEPARATOR ', ') as all_credentials
             FROM {$cardholders_table} c
             LEFT JOIN {$properties_table} p ON c.property_id = p.property_id
             LEFT JOIN ac_credentials cred ON c.id = cred.cardholder_id
             WHERE (c.first_name LIKE %s
                OR c.last_name LIKE %s
                OR p.house_number LIKE %s
                OR p.street_name LIKE %s
                OR cred.credential_value LIKE %s)
               AND c.cardholder_status NOT IN ('archived', 'purged')
               AND cardholder_type = 'resident'
             GROUP BY c.id
             LIMIT 10",
            $wildcard_term,
            $wildcard_term,
            $wildcard_term,
            $wildcard_term,
            $wildcard_term
        ));

        $suggestions = [];
        if ($results) {
            foreach ($results as $result) {
                $label = trim($result->first_name . ' ' . $result->last_name) . ' (' . ($result->street_address ?: 'No Address') . ')';
                $suggestions[] = [
                    'id' => $result->id,
                    'label' => $label,
                    'value' => $label,
                    'first_name' => $result->first_name,
                    'last_name' => $result->last_name,
                    'street_address' => $result->street_address,
                ];
            }
        }
        wp_send_json_success($suggestions);
    }

    public function handle_get_cardholder_photo() {
        if ( ! is_user_logged_in() || ! current_user_can('manage_options') ) {
            status_header(403);
            die('Access Denied.');
        }

        $cardholder_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ( ! $cardholder_id ) {
            status_header(404);
            die('Not Found: Invalid ID.');
        }

        global $wpdb;
        $photo_data = $wpdb->get_var($wpdb->prepare("SELECT photo FROM ac_cardholders WHERE id = %d", $cardholder_id));

        if ( empty($photo_data) ) {
            status_header(404);
            die('Not Found: No photo available.');
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . strlen($photo_data));
        echo $photo_data;
        exit;
    }

    public function handle_bulk_cardholder_action() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'fsbhoa_bulk_cardholder_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'fsbhoa-ac' ) );
        }

        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '-1';

        // If no action selected or no cardholders checked, just send them back
        if ( $bulk_action === '-1' || empty( $_POST['cardholder_ids'] ) || ! is_array( $_POST['cardholder_ids'] ) ) {
            wp_safe_redirect( wp_get_referer() );
            exit;
        }

        $cardholder_ids = array_map( 'absint', $_POST['cardholder_ids'] );
        $archived_count = 0;

        if ( $bulk_action === 'archive' ) {
            foreach ( $cardholder_ids as $id ) {
                // Fetch active credentials before archiving
                $creds = $wpdb->get_col($wpdb->prepare("SELECT credential_value FROM ac_credentials WHERE cardholder_id = %d AND status = 'active'", $id));
                $credential_list = empty($creds) ? '' : implode(',', $creds);

                $result = fsbhoa_archive_and_delete_cardholder( $id );
                if ( ! is_wp_error( $result ) ) {
                    $archived_count++;
                    $log_data = json_encode(['credentials' => $credential_list, 'action' => 'bulk_archive']);
                    fsbhoa_log_pending_change('cardholder', $id, $log_data);
                }
            }
        }

        // Figure out where to send them back to
        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $page_object = get_page_by_path('cardholder');
            $redirect_url = $page_object ? get_permalink($page_object->ID) : home_url('/');
        }

        // Clean up URL and attach success message
        $redirect_url = remove_query_arg( array( 'action', 'cardholder_id', '_wpnonce', 'message', 'archived_count' ), $redirect_url );

        if ( $archived_count > 0 ) {
            $redirect_url = add_query_arg( array( 'message' => 'bulk_archived', 'archived_count' => $archived_count ), $redirect_url );
        }

        // Redirect logic
        if ( $bulk_action === 'archive' && $archived_count > 0 ) {
            // Redirect to the Archived Cardholders page
            $page_object = get_page_by_path('archived-cardholders');
            $redirect_url = $page_object ? get_permalink($page_object->ID) : home_url('/');
            $redirect_url = add_query_arg( array( 'message' => 'bulk_archived', 'archived_count' => $archived_count ), $redirect_url );
            wp_safe_redirect( $redirect_url );
            exit;
        } else {
            // Default fallback if we add other bulk actions later
            $redirect_url = wp_get_referer() ?: home_url('/');
            $redirect_url = remove_query_arg( array( 'action', 'cardholder_id', '_wpnonce', 'message', 'archived_count' ), $redirect_url );
            wp_safe_redirect( $redirect_url );
            exit;
        }
        exit;
    }


    public function ajax_merge_cardholders_callback() {
        check_ajax_referer('fsbhoa_property_search_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        $primary_id   = isset($_POST['primary_id']) ? absint($_POST['primary_id']) : 0;
        $duplicate_id = isset($_POST['duplicate_id']) ? absint($_POST['duplicate_id']) : 0;

        // Call the shared merge helper (Destination = primary_id, Source = duplicate_id)
        $result = fsbhoa_merge_cardholders($primary_id, $duplicate_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('Cardholders merged successfully.');
    }

    // This will capture the database content for the current household and put
    // it into the pastebuffer so you can pass this info to AI for debugging.
    public function ajax_copy_household_debug_callback() {
        check_ajax_referer( 'fsbhoa_property_search_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $property_id  = isset( $_POST['property_id'] ) ? absint( $_POST['property_id'] ) : 0;
        $household_id = isset( $_POST['household_id'] ) ? absint( $_POST['household_id'] ) : 0;

        if ( ! $property_id ) {
            wp_send_json_error( 'Missing property ID.' );
        }

        global $wpdb;

        // If a specific household was targeted by the button, filter by it; otherwise show all property occupants
        if ( $household_id > 0 ) {
            $where_clause = $wpdb->prepare( "c.household_id = %d", $household_id );
        } else {
            $where_clause = $wpdb->prepare( "c.property_id = %d", $property_id );
        }

        $rows = $wpdb->get_results(
            "SELECT
                c.id AS cardholder_id,
                CONCAT(c.first_name, ' ', c.last_name) AS name,
                c.resident_type,
                c.household_id,
                h.household_name,
                COUNT(DISTINCT v.vehicle_id) AS vehicle_count,
                GROUP_CONCAT(DISTINCT cred.credential_value ORDER BY cred.credential_value SEPARATOR ', ') AS credentials
            FROM ac_cardholders c
            LEFT JOIN ac_households h ON c.household_id = h.household_id
            LEFT JOIN ac_vehicles v ON v.household_id = c.household_id
            LEFT JOIN ac_credentials cred ON cred.cardholder_id = c.id
            WHERE {$where_clause}
              AND c.cardholder_status != 'purged'
            GROUP BY c.id, c.first_name, c.last_name, c.resident_type, c.household_id, h.household_name
            ORDER BY c.resident_type, c.id",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            wp_send_json_success( "No occupants found for Household ID {$household_id}." );
        }

        $hh_label = $rows[0]['household_name'] ?? 'Household';
        $output  = "Property ID: {$property_id} | Household ID: {$household_id} ({$hh_label})\n";
        $output .= "ID\tName\tType\tHH_ID\tHousehold Name\tVehicles\tCredentials\n";
        $output .= str_repeat( "-", 80 ) . "\n";

        foreach ( $rows as $r ) {
            $output .= sprintf(
                "%d\t%s\t%s\t%s\t%s\t%d\t%s\n",
                $r['cardholder_id'],
                $r['name'],
                $r['resident_type'],
                $r['household_id'] ?? 'NULL',
                $r['household_name'] ?? 'NULL',
                $r['vehicle_count'],
                $r['credentials'] ?? 'NULL'
            );
        }

        wp_send_json_success( $output );
    }
}



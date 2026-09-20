<?php
/**
 * Handles all admin-post actions for the Archived Cardholder management page.
 * REFACTORED FOR SOFT-DELETE ARCHITECTURE.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Fsbhoa_Archived_Cardholder_Actions {

	public function __construct() {
		add_action( 'admin_post_fsbhoa_restore_archived_cardholder', [ $this, 'handle_restore_action' ] );
		add_action( 'admin_post_fsbhoa_purge_archived_cardholder', [ $this, 'handle_purge_action' ] );
		add_action( 'admin_post_fsbhoa_confirm_merge', [ $this, 'handle_confirm_merge_action' ] );
		add_action( 'admin_post_fsbhoa_update_archived_notes', [ $this, 'handle_update_archived_notes_action' ] );
		add_action( 'wp_ajax_fsbhoa_update_archived_notes', [ $this, 'ajax_update_archived_notes' ] );
		add_action( 'admin_post_fsbhoa_bulk_archived_action', [ $this, 'handle_bulk_archived_action' ] );
		add_action( 'wp_ajax_fsbhoa_ajax_purge_cardholder', [ $this, 'ajax_purge_cardholder_callback' ] );
	}

	/**
	 * Handles restoring an archived cardholder back to 'active' status.
	 */
    public function handle_restore_action() {
        $cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;
        if ( ! $cardholder_id ) {
            wp_die( 'Invalid cardholder ID specified.', 'Error', [ 'back_link' => true ] );
        }
        check_admin_referer( 'fsbhoa_restore_archived_cardholder_' . $cardholder_id );

        require_once plugin_dir_path( dirname( __DIR__ ) ) . 'includes/fsbhoa-cardholder-functions.php';

        $result = fsbhoa_restore_cardholder( $cardholder_id );

        if ( is_wp_error( $result ) ) {
            wp_die( $result->get_error_message(), 'Error', [ 'back_link' => true ] );
        }

        // Redirect to Live Cardholders and highlight the restored ID
        $page_object  = get_page_by_path( 'cardholder' );
        $redirect_url = $page_object ? get_permalink( $page_object->ID ) : home_url( '/' );
        $redirect_url = add_query_arg( [ 'message' => 'cardholder_restored', 'highlight' => $cardholder_id ], $redirect_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }

	/**
	 * Handles "purging" a cardholder. This sets their status to 'purged',
	 * removes credentials to free badge IDs, and keeps the cardholder row for historical logs.
	 */
	public function handle_purge_action() {
		global $wpdb;
		$cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;
		if ( ! $cardholder_id ) {
			wp_die( 'Invalid cardholder ID specified.', 'Error', [ 'back_link' => true ] );
		}
		check_admin_referer( 'fsbhoa_purge_cardholder_' . $cardholder_id );

		$result = $wpdb->update(
			'ac_cardholders',
			[ 'cardholder_status' => 'purged' ],
			[ 'id' => $cardholder_id ],
			[ '%s' ],
			[ '%d' ]
		);

		// DB ERROR CHECK
		if ( false === $result ) {
			wp_die( 'Database error while purging the cardholder. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', [ 'back_link' => true ] );
		}

		// Clean out associated credentials so strings/badges are not orphaned or blocking unique checks
		$wpdb->delete( 'ac_credentials', [ 'cardholder_id' => $cardholder_id ], [ '%d' ] );

		$redirect_url = remove_query_arg( [ 'action', 'cardholder_id', '_wpnonce' ], wp_get_referer() );
		$redirect_url = add_query_arg( 'message', 'cardholder_purged', $redirect_url );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function ajax_purge_cardholder_callback() {
		check_ajax_referer( 'fsbhoa_property_search_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$cardholder_id = isset( $_POST['cardholder_id'] ) ? absint( $_POST['cardholder_id'] ) : 0;

		if ( ! $cardholder_id ) {
			wp_send_json_error( 'Invalid cardholder ID specified for purge.' );
		}

		global $wpdb;

		$result = $wpdb->update(
			'ac_cardholders',
			[ 'cardholder_status' => 'purged' ],
			[ 'id' => $cardholder_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( false === $result ) {
			wp_send_json_error( 'Database error while purging the cardholder. DB Error: ' . esc_html( $wpdb->last_error ) );
		}

		// Clean out associated credentials so strings/badges are not orphaned or blocking unique checks
		$wpdb->delete( 'ac_credentials', [ 'cardholder_id' => $cardholder_id ], [ '%d' ] );

		// Log the change for hardware sync tracking
		if ( function_exists( 'fsbhoa_log_pending_change' ) ) {
			fsbhoa_log_pending_change( 'cardholder', $cardholder_id, json_encode( [ 'action' => 'purged_record' ] ) );
		}

		wp_send_json_success( 'Cardholder purged successfully.' );
	}

	/**
	 * Handles the final merge action.
	 * REFACTORED to use prepared statements for all database writes, ensuring
	 * that binary photo data is handled safely and correctly.
	 */
	public function handle_confirm_merge_action() {
		check_admin_referer( 'fsbhoa_confirm_merge_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to merge cardholders.' );
		}

		$source_id      = isset( $_POST['source_cardholder_id'] ) ? absint( $_POST['source_cardholder_id'] ) : 0;
		$destination_id = isset( $_POST['destination_cardholder_id'] ) ? absint( $_POST['destination_cardholder_id'] ) : 0;

		$result = fsbhoa_merge_cardholders( $destination_id, $source_id );

		if ( is_wp_error( $result ) ) {
			wp_die( $result->get_error_message(), 'Error', [ 'back_link' => true ] );
		}

		$page_object  = get_page_by_path( 'cardholder' );
		$redirect_url = $page_object ? get_permalink( $page_object->ID) : home_url( '/' );
		$redirect_url = add_query_arg( [ 'message' => 'merge_success', 'highlight' => $destination_id ], $redirect_url );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handles updating the notes for a single archived cardholder.
	 */
	public function handle_update_archived_notes_action() {
		global $wpdb;

		// Security and permission checks
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'fsbhoa_update_archived_notes_nonce' ) ) {
			wp_die( 'Security check failed.', 'Error', [ 'back_link' => true ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to perform this action.', 'Error', [ 'back_link' => true ] );
		}

		$cardholder_id = isset( $_POST['cardholder_id'] ) ? absint( $_POST['cardholder_id'] ) : 0;
		if ( ! $cardholder_id ) {
			wp_die( 'Invalid cardholder ID.', 'Error', [ 'back_link' => true ] );
		}

		// Sanitize and update the notes
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$result = $wpdb->update(
			'ac_cardholders',
			[ 'notes' => $notes ],
			[ 'id' => $cardholder_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( $result === false ) {
			wp_die( 'Database error while updating notes.', 'Error', [ 'back_link' => true ] );
		}

		// Redirect back to the preview page with a success message
		$redirect_url = wp_get_referer();
		$redirect_url = add_query_arg( 'message', 'notes_updated', $redirect_url );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function ajax_update_archived_notes() {
		check_ajax_referer( 'fsbhoa_archived_notes_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}

		$cardholder_id = isset( $_POST['cardholder_id'] ) ? absint( $_POST['cardholder_id'] ) : 0;
		if ( ! $cardholder_id ) {
			wp_send_json_error( 'Invalid cardholder ID.' );
		}

		global $wpdb;
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$result = $wpdb->update(
			'ac_cardholders',
			[ 'notes' => $notes ],
			[ 'id' => $cardholder_id ],
			[ '%s' ], [ '%d' ]
		);

		if ( $result === false ) {
			wp_send_json_error( 'Database error updating notes: ' . $wpdb->last_error );
		}

		wp_send_json_success( 'Notes updated successfully.' );
	}

	/**
	 * Handles bulk restoring or purging of archived cardholders.
	 */
	public function handle_bulk_archived_action() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'fsbhoa_bulk_archived_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'fsbhoa-ac' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}

		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '-1';

		if ( $bulk_action === '-1' || empty( $_POST['cardholder_ids'] ) || ! is_array( $_POST['cardholder_ids'] ) ) {
			wp_safe_redirect( wp_get_referer() );
			exit;
		}

		global $wpdb;
		$cardholder_ids  = array_map( 'absint', $_POST['cardholder_ids'] );
		$processed_count = 0;

		if ( $bulk_action === 'purge' ) {
			// Bulk Purge: Update status to 'purged' for all selected IDs
			$ids_string = implode( ',', $cardholder_ids );
			$sql = "UPDATE ac_cardholders SET cardholder_status = 'purged' WHERE id IN ($ids_string)";
			$result = $wpdb->query( $sql );
			if ( $result !== false ) {
				$processed_count = count( $cardholder_ids );
				// Clean out credentials for all purged records
				$wpdb->query( "DELETE FROM ac_credentials WHERE cardholder_id IN ($ids_string)" );
            } 
        } elseif ( $bulk_action === 'restore' ) {
            require_once plugin_dir_path( dirname( __DIR__ ) ) . 'includes/fsbhoa-cardholder-functions.php';

            foreach ( $cardholder_ids as $cardholder_id ) {
                $res = fsbhoa_restore_cardholder( $cardholder_id );
                if ( ! is_wp_error( $res ) ) {
                    $processed_count++;
                }
            }
        }

		if ( $bulk_action === 'restore' && $processed_count > 0 ) {
			// Redirect to Live Cardholders and highlight the first restored ID
			$page_object  = get_page_by_path( 'cardholder' );
			$redirect_url = $page_object ? get_permalink( $page_object->ID ) : home_url( '/' );
			$highlight_id = $cardholder_ids[0]; // Grab the first one to highlight

			$redirect_url = add_query_arg( [ 'message' => 'bulk_restored', 'processed_count' => $processed_count, 'highlight' => $highlight_id ], $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;

		} elseif ( $bulk_action === 'purge' && $processed_count > 0 ) {
			// Stay on the Archive page for purge
			$redirect_url = remove_query_arg( [ 'action', 'cardholder_id', '_wpnonce', 'message', 'processed_count' ], wp_get_referer() );
			$redirect_url = add_query_arg( [ 'message' => 'bulk_purged', 'processed_count' => $processed_count ], $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		wp_safe_redirect( wp_get_referer() );
		exit;
	}

}


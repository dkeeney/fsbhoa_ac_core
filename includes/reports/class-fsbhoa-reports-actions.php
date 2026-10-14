<?php
/**
 * Handles actions for the Reports component, like CSV exports.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Reports_Actions {

    /**
     * Constructor. Hooks into WordPress.
     */
    public function __construct() {
        add_action('admin_post_fsbhoa_export_access_log', array($this, 'handle_export'));
        add_action('admin_post_fsbhoa_export_selected_logs', array($this, 'handle_export_selected'));
    }

    /**
     * Handles the CSV export request for the access log.
     */
    public function handle_export() {
        // Security checks
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'fsbhoa_export_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        global $wpdb;

        // Get filter parameters from the URL
        $search     = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
        $start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
        $end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';
        $gate_id    = isset( $_GET['gate_id'] ) ? absint( $_GET['gate_id'] ) : 0;

        $base_query = " FROM ac_access_log l LEFT JOIN ac_cardholders ch ON l.cardholder_id = ch.id LEFT JOIN ac_property p ON ch.property_id = p.property_id LEFT JOIN ac_controllers c ON l.controller_identifier = c.uhppoted_device_id LEFT JOIN ac_doors d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller ";
        
        $where_clauses = [];
        if ( ! empty($start_date) ) { $where_clauses[] = $wpdb->prepare( "l.event_timestamp >= %s", date( 'Y-m-d 00:00:00', strtotime( $start_date ) ) ); }
        if ( ! empty($end_date) ) { $where_clauses[] = $wpdb->prepare( "l.event_timestamp <= %s", date( 'Y-m-d 23:59:59', strtotime( $end_date ) ) ); }
        if ( ! empty($gate_id) ) { $where_clauses[] = $wpdb->prepare( "d.door_record_id = %d", $gate_id ); }
        if ( ! empty($search) ) { $search_term = '%' . $wpdb->esc_like( $search ) . '%'; $where_clauses[] = $wpdb->prepare( "(CONCAT(ch.first_name, ' ', ch.last_name) LIKE %s OR p.street_address LIKE %s OR d.friendly_name LIKE %s OR l.event_description LIKE %s OR l.rfid_id LIKE %s)", $search_term, $search_term, $search_term, $search_term, $search_term ); }
        $where_sql = ! empty( $where_clauses ) ? " WHERE " . implode( ' AND ', $where_clauses ) : '';

        $data_query = " SELECT l.event_timestamp, CONCAT(ch.first_name, ' ', ch.last_name) as cardholder, ch.resident_type, p.street_address as property, d.friendly_name as gate_name, l.access_granted, l.event_description, l.rfid_id {$base_query} {$where_sql} ORDER BY l.event_timestamp DESC ";
        
        $results = $wpdb->get_results( $data_query, ARRAY_A );

        // ** NEW: Add database error check **
        if ( $wpdb->last_error ) {
            wp_die( 'Database error generating export: ' . esc_html( $wpdb->last_error ) );
        }

        $filename = "access-log-" . current_time('Y-m-d') . ".csv";
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Timestamp', 'Cardholder', 'Type', 'Property', 'Gate', 'Result', 'Description', 'RFID']);
        foreach ($results as $row) {
            $row['access_granted'] = is_null($row['access_granted']) ? 'N/A' : ($row['access_granted'] ? 'Granted' : 'Denied');
            fputcsv($output, $row);
        }
        fclose($output);
        die();
    }


    public function handle_export_selected() {
        // Security checks
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'fsbhoa_export_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        $log_ids_str = isset($_POST['log_ids']) ? sanitize_text_field($_POST['log_ids']) : '';
        $log_ids = array_filter(array_map('absint', explode(',', $log_ids_str)));

        if (empty($log_ids)) {
            wp_die('No log entries were selected for export.');
        }

        global $wpdb;
        $ids_placeholder = implode(',', array_fill(0, count($log_ids), '%d'));
        
        $base_query = " FROM ac_access_log l LEFT JOIN ac_cardholders ch ON l.cardholder_id = ch.id LEFT JOIN ac_property p ON ch.property_id = p.property_id LEFT JOIN ac_controllers c ON l.controller_identifier = c.uhppoted_device_id LEFT JOIN ac_doors d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller ";
        $where_sql = " WHERE l.log_id IN ($ids_placeholder) ";
        
        $data_query = " SELECT l.event_timestamp, CONCAT(ch.first_name, ' ', ch.last_name) as cardholder, ch.resident_type, p.street_address as property, d.friendly_name as gate_name, l.access_granted, l.event_description, l.rfid_id {$base_query} {$where_sql} ORDER BY l.event_timestamp DESC ";

        $results = $wpdb->get_results( $wpdb->prepare($data_query, $log_ids), ARRAY_A );
        if ( $wpdb->last_error ) {
            wp_die( 'Database error generating export: ' . esc_html( $wpdb->last_error ) );
        }

        $filename = "access-log-selected-" . current_time('Y-m-d') . ".csv";
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Timestamp', 'Cardholder', 'Type', 'Property', 'Gate', 'Result', 'Description', 'RFID']);
        foreach ($results as $row) {
            $row['access_granted'] = is_null($row['access_granted']) ? 'N/A' : ($row['access_granted'] ? 'Granted' : 'Denied');
            fputcsv($output, $row);
        }
        fclose($output);
        die();
    }
}


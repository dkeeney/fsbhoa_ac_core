<?php
// includes/admin/class-fsbhoa-test-suite-actions.php

if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Test_Suite_Actions {

    public function __construct( $register_hooks = true ) {
        if ( $register_hooks ) {
            add_action('wp_ajax_fsbhoa_run_regression_test', array($this, 'run_test_step'));
        }
    }

    /**
     * The test suite's steps, in order. Extension plugins add their own steps with the
     * 'fsbhoa_regression_test_steps' filter. Each step is
     *   [ 'id' => unique id, 'label' => text shown while it runs, 'callback' => callable ]
     * The callback returns a success message (string) or a WP_Error.
     * @return array
     */
    public static function get_steps() {
        $self  = new self( false );
        $steps = [];

        // Only when a hardware plugin can simulate an event; otherwise there is nothing to test.
        if ( has_filter( 'fsbhoa_simulate_hardware_event' ) ) {
            $steps[] = [ 'id' => 'run_hardware_test',    'label' => 'Triggering hardware event from event_service...',
                         'callback' => [ $self, 'run_hardware_test' ] ];
            $steps[] = [ 'id' => 'verify_hardware_test', 'label' => 'Verifying hardware event in database...',
                         'callback' => function () use ( $self ) { $self->verify_database_event( '11111111', 'Hardware event logged to DB' ); } ];
        }

        $steps[] = [ 'id' => 'run_access_service_test',    'label' => 'Writing a test sign-in through the access service...',
                     'callback' => [ $self, 'run_access_service_test' ] ];
        $steps[] = [ 'id' => 'verify_access_service_test', 'label' => 'Verifying the test sign-in in database...',
                     'callback' => function () use ( $self ) { $self->verify_database_event( '22222222', 'Access service sign-in logged to DB' ); } ];

        // A plugin that isn't installed adds no steps, so the rest still run.
        return apply_filters( 'fsbhoa_regression_test_steps', $steps );
    }

    public function run_test_step() {
        check_ajax_referer('fsbhoa_test_suite_nonce', 'nonce');
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.', 403 );
        }

        $step_id = sanitize_text_field($_POST['test_step']);

        foreach ( self::get_steps() as $step ) {
            if ( $step['id'] !== $step_id ) {
                continue;
            }
            // Core's own steps send their JSON response directly; others return a result.
            $result = call_user_func( $step['callback'] );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
            wp_send_json_success( is_string( $result ) && $result !== '' ? $result : 'Step completed.' );
        }
        wp_send_json_error('Invalid test step.');
    }

    public function run_hardware_test() {
        // Broadcast the test intent to hardware plugins
        $result = apply_filters('fsbhoa_simulate_hardware_event', false);

        if ( is_wp_error($result) ) {
            wp_send_json_error('Test failed: ' . $result->get_error_message());
        } elseif ($result !== false) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error('Test failed: No hardware plugin configured to run simulated events.');
        }
    }

    public function verify_database_event($rfid, $success_message) {
        global $wpdb;
        $table = 'ac_access_log';
        
        // Add a delay to ensure the event has time to be processed
        sleep(2);

        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE rfid_id = %s AND event_timestamp > NOW() - INTERVAL 15 SECOND",
            $rfid
        );
        
        // --- DEBUGGING LOGS START ---
        error_log("--- Verifying Database Event ---");
        error_log("Running Query: " . $query);
        $event_count = $wpdb->get_var($query);
        error_log("Query Result (COUNT): " . $event_count);
        // --- DEBUGGING LOGS END ---

        if ($event_count > 0) {
            wp_send_json_success($success_message);
        } else {
            wp_send_json_error("Verification failed: Event for RFID {$rfid} not found in database.");
        }
    }

    /**
     * Writes a sign-in for the System test card straight through the access service,
     * the same path kiosk and gate events take. Extension plugins test their own
     * entry points (see 'fsbhoa_regression_test_steps').
     */
    public function run_access_service_test() {
        $result = Fsbhoa_Access_Service::process_and_write_event( [
            'event_timestamp'       => current_time( 'mysql' ),
            'controller_identifier' => 'diagnostics',
            'door_number'           => 1,
            'rfid_id'               => '22222222',
            'event_type_code'       => 100,
            'event_description'     => 'Diagnostics test sign-in',
            'access_granted'        => 1,
            'guest_count'           => 0,
            'amenity_name'          => 'Test Amenity',
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( 'Access service test failed: ' . $result->get_error_message() );
        }
        wp_send_json_success( 'Test sign-in written (log ' . $result . ').' );
    }

}

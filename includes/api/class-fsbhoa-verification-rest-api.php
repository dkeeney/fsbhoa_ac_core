<?php
/**
 * Handles Generic Access Verification REST API endpoints.
 * Used by external systems (Lighting, etc.) to verify resident status/history.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Verification_REST_API {

    private $namespace = 'fsbhoa/v1';

    public function register_routes() {
        // Endpoint: /wp-json/fsbhoa/v1/access/verify-email
        register_rest_route( $this->namespace, '/access/verify-email', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'verify_email_access_callback' ),
            'permission_callback' => array( $this, 'api_key_permission_check' ),
        ) );
    }

    /**
     * Security: callers send the Access Verification API Key (General settings) in X-API-KEY.
     * This is the one key for every service that calls core's REST API (lighting, the
     * hardware event services, the monitor service), so other route classes use it too:
     * 'permission_callback' => [ 'Fsbhoa_Verification_REST_API', 'api_key_permission_check' ].
     */
    public static function api_key_permission_check( WP_REST_Request $request ) {
        $provided_key = $request->get_header('X-API-KEY');
        
        // Its own key, so it doesn't depend on (or break with) another plugin's key.
        $stored_key = get_option('fsbhoa_ac_verify_api_key', '');

        if ( !empty($stored_key) && !empty($provided_key) && hash_equals($stored_key, $provided_key) ) {
            return true;
        }
        return new WP_Error('rest_forbidden', 'Invalid or Missing API Key.', ['status' => 403]);
    }

    /**
     * Checks if a user (by email) has entered the facility recently.
     * Params: ?email=user@example.com
     */
    public function verify_email_access_callback( WP_REST_Request $request ) {
        global $wpdb;

        $email = sanitize_email( $request->get_param( 'email' ) );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'bad_request', 'Invalid email address.', ['status' => 400] );
        }

        // Configuration: 4 Hour window
        // In the future, this could be a plugin setting: get_option('fsbhoa_ac_verification_window', 4)
        $hours = 4;
        
        // Table names
        $log_table = 'ac_access_log';
        $cardholders_table = 'ac_cardholders';
        
        // Query: Join Logs to Cardholders to find a valid swipe by this email
        $query = $wpdb->prepare("
            SELECT log.event_timestamp, log.amenity_name, log.controller_identifier, log.door_number, ch.rfid_id
            FROM {$log_table} log
            INNER JOIN {$cardholders_table} ch ON log.cardholder_id = ch.id
            WHERE ch.email = %s
            AND log.access_granted = 1
            AND log.event_timestamp > (NOW() - INTERVAL %d HOUR)
            ORDER BY log.event_timestamp DESC
            LIMIT 1
        ", $email, $hours);

        $swipe = $wpdb->get_row( $query );

        if ( $swipe ) {
            return new WP_REST_Response([
                'isValid' => true,
                'rfid_id' => $swipe->rfid_id,
                'message' => 'Valid recent entry found.',
                'details' => [
                    'time' => $swipe->event_timestamp,
                    'amenity' => $swipe->amenity_name,
                    'controller' => $swipe->controller_identifier
                ]
            ], 200);
        }

        return new WP_REST_Response([
            'isValid' => false, 
            'message' => 'No recent entry found.'
        ], 200);
    }
}


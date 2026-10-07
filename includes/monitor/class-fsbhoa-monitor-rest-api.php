<?php

/**
 * Handles REST API endpoints for the Live Monitor component.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-sync-functions.php';

class Fsbhoa_Monitor_REST_API {

    private $namespace = 'fsbhoa/v1';

    public function register_routes() {
        // Endpoint for the monitor_service to fetch a single, enriched event by its log ID.
        register_rest_route( $this->namespace, '/monitor/event', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_event_by_id_callback' ),
            'permission_callback' => '__return_true', // Should be secured with an API key
             'args'               => array(
                'record_id' => array(
                    'required'          => true,
                    'validate_callback' => array( $this, 'is_numeric_callback' )
                ),
            ),
        ) );

        // Fetch HTML summary card for a given cardholder ID
        register_rest_route( $this->namespace, '/monitor/cardholder-summary', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_cardholder_summary_callback' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'cardholder_id' => array(
                    'required'          => true,
                    'validate_callback' => array( $this, 'is_numeric_callback' ),
                ),
            ),
        ) );
        
        // This route is called by the frontend JavaScript to get all gate data for the map
        register_rest_route( $this->namespace, '/monitor/gates', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_all_gates_callback' ),
            'permission_callback' => '__return_true',
        ) );

        // This route is called by the Go event_service to log a raw hardware event to the database
        register_rest_route( $this->namespace, '/monitor/log-event', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'log_event_callback' ),
            'permission_callback' => '__return_true', // Internal service-to-service call
        ) );

        // This route is called by the monitor page to get recent historical events
        register_rest_route( $this->namespace, '/monitor/recent-activity', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_recent_activity_callback' ),
            'permission_callback' => '__return_true',
        ) );

        // This route is called by the monitor page to manually set a door's state
        register_rest_route( $this->namespace, '/monitor/set-door-state', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'set_door_state_callback' ),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ) );

        // This route is called by the monitor page to check if the status group (Residents) has access now
        register_rest_route( $this->namespace, '/monitor/group-status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_group_status_callback' ),
            'permission_callback' => '__return_true', // Public read-only status
        ) );

        // This route is called by the monitor page to get the current active schedule name
        register_rest_route( $this->namespace, '/monitor/current-schedule', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_current_schedule_callback' ),
            'permission_callback' => '__return_true', // Public read-only for the monitor
        ) );

        register_rest_route( $this->namespace, '/monitor/set-door-state', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'set_door_state_callback' ),
            'permission_callback' => function ( WP_REST_Request $request ) {
                // 1. Allow Admins
                if ( current_user_can( 'manage_options' ) ) {
                    return true;
                }
                
                // 2. Allow Kiosk via API Key Header
                $stored_key = get_option('fsbhoa_ac_kiosk_api_key', '');
                $provided_key = $request->get_header('X-FSBHOA-Kiosk-Key');
                
                if ( !empty($stored_key) && !empty($provided_key) && hash_equals($stored_key, $provided_key) ) {
                    return true;
                }
                
                return false;
            },
        ) );
    }

    public function is_numeric_callback( $value, $request, $param ) {
        return is_numeric( $value );
    }

    public function get_all_gates_callback( WP_REST_Request $request ) {
        global $wpdb;
        $doors_table = 'ac_doors';
        $controllers_table = 'ac_controllers';
        $role_filter = $request->get_param('role');
        
        $query = "
            SELECT d.door_record_id, d.friendly_name, d.door_number_on_controller, d.map_x, d.map_y, c.uhppoted_device_id
            FROM {$doors_table} d
            JOIN {$controllers_table} c ON d.controller_record_id = c.controller_record_id
        ";

        // Apply Filter if present
        if ( !empty($role_filter) ) {
            // Use prepare to sanitize the role string
            $query = $wpdb->prepare($query . " WHERE d.door_role = %s", $role_filter);
        }

        $gates = $wpdb->get_results( $query, ARRAY_A );

        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error fetching gates.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }
        return new WP_REST_Response( $gates ?? [], 200 );
    }

    /**
     * Callback to receive an event from the Go event_service and log it to the database.
     * It also sends a notification to the new monitor_service.
     */
    public function log_event_callback( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_json_params();
        // Get West Gate hardware details once

        error_log('[RAW EVENT DATA] ' . print_r($params, true));

        $serial = strval($params['SerialNumber'] ?? '');
        $provided_door = absint($params['Door'] ?? 0);
        $zone_name = sanitize_text_field($params['ZoneName'] ?? '');

        // Allow hardware plugins to intercept and modify the raw event data
        // (e.g., mapping virtual zones to physical doors)
        $mapped_data = apply_filters('fsbhoa_hardware_map_event_data', [
            'serial' => $serial,
            'door'   => $provided_door,
            'zone'   => $zone_name
        ], $params);

        $serial = $mapped_data['serial'];
        $provided_door = $mapped_data['door'];


        if ( empty($serial) || $provided_door == 0 ) {
            return new WP_Error( 'bad_request', 'Missing required event parameters.', array( 'status' => 400 ) );
        }
        $raw_card_number = absint($params['CardNumber'] ?? 0);

	    $log_data = [
		    'event_timestamp'       => $params['Timestamp'] ?? current_time('mysql'),
		    'controller_identifier' => $serial,
		    'door_number'           => $provided_door,
            'rfid_id' => ($raw_card_number === 0) ? 
                     NULL : // If the number is 0 (the 'no card' indicator), set to NULL.
                     sprintf('%08d', $raw_card_number), // Otherwise, pad the number with leading zeros to 8 characters.
		    'event_type_code'       => absint($params['Reason']),
            'event_description'     => isset($params['EventMessage']) ? sanitize_text_field($params['EventMessage']) : 'Unknown Event',
		    'access_granted'        => isset($params['Granted']) ? ($params['Granted'] ? 1 : 0) : null,
            'amenity_name'          => NULL,
	    ];

        // Process the event and write to the access log via the centralized service.
        $result = Fsbhoa_Access_Service::process_and_write_event( $log_data );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            $message = $result->get_error_message();
        
            if ( $error_code === 'rate_limit' ) {
                // SCENARIO: Rate Limited. Handled successfully by discarding. Return 200 OK.
                error_log("FSBHOA EVENT INFO: Event discarded due to rate limit.");
                return new WP_REST_Response( ['status' => 'success', 'message' => 'Event discarded (rate limited).'], 200 );
            }
        
            // SCENARIO: Actual Database Failure or other fatal error.
            error_log("FSBHOA EVENT CRITICAL: Failed to log event. Error: " . $message);
            $status = $error_code === 'db_error' ? 500 : 400;
            return new WP_REST_Response( ['status' => 'failure', 'message' => $message], $status );
        }

        return new WP_REST_Response( ['status' => 'success', 'message' => 'Event logged.'], 200 );
    }

    /**
     * Callback for the monitor_service to fetch a single enriched event.
     */
    public function get_event_by_id_callback( WP_REST_Request $request ) {
        global $wpdb;
        $log_id = absint($request->get_param('record_id'));

        $log_table = 'ac_access_log';
        $cardholders_table = 'ac_cardholders';
        $doors_table = 'ac_doors';
        $controllers_table = 'ac_controllers';
        $property_table = 'ac_property';

        $query = $wpdb->prepare("SELECT l.event_timestamp, l.access_granted, l.event_description, cred.credential_value AS rfid_id, l.controller_identifier, ch.id as cardholder_id, ch.first_name, ch.last_name, ch.photo, d.friendly_name AS gate_name, c.friendly_name AS controller_name, d.door_record_id, p.street_address
             FROM {$log_table} AS l
             LEFT JOIN {$cardholders_table} AS ch ON l.cardholder_id = ch.id
             LEFT JOIN {$controllers_table} AS c ON l.controller_identifier = c.uhppoted_device_id
             LEFT JOIN ac_credentials AS cred ON ch.id = cred.cardholder_id AND cred.credential_type = 'MIFARE_BADGE'
             LEFT JOIN {$doors_table} AS d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller
             LEFT JOIN {$property_table} AS p ON ch.property_id = p.property_id
             WHERE l.log_id = %d",
             $log_id
        );
        $event = $wpdb->get_row($query, ARRAY_A);

        if (!$event) {
            return new WP_Error('not_found', 'Event not found.', ['status' => 404]);
        }
        
        $cardholder_name = trim($event['first_name'] . ' ' . $event['last_name']);
        if (empty($cardholder_name) && !empty($event['rfid_id']) && $event['rfid_id'] != 0) {
            $cardholder_name = 'Unknown Card (' . $event['rfid_id'] . ')';
        } elseif (empty($cardholder_name)) {
            $cardholder_name = 'System Event';
        }

        $formatted_event = [
            'logId'          => (int)$log_id,
            'eventType'      => $event['access_granted'] ? 'accessGranted' : 'accessDenied',
            'cardholderName' => $cardholder_name,
            'cardholderId'   => !empty($event['cardholder_id']) ? (int)$event['cardholder_id'] : null,
            'photoURL'       => !empty($event['photo']) ? 'data:image/jpeg;base64,' . base64_encode($event['photo']) : '',
            'gateName'       => $event['gate_name'] ?: ($event['controller_name'] ?: 'Unknown Gate'),
            'timestamp'      => date('g:i:s A', strtotime($event['event_timestamp'])),
            'eventMessage'   => $event['event_description'],
            'controller_identifier' => $event['controller_identifier'],
            'cardNumber'     => (int)ltrim($event['rfid_id'], '0'),
            'doorRecordId'   => (int)$event['door_record_id'],
            'streetAddress'  => $event['street_address'] ?? 'N/A',
        ];

        return new WP_REST_Response($formatted_event, 200);
    }
    
    /**
     *  Callback to get recent events to populate the monitor on load.
     */
    public function get_recent_activity_callback( WP_REST_Request $request ) {
        global $wpdb;
        $query = $this->get_recent_activity_query();
        $results = $wpdb->get_results($query, ARRAY_A);

        if ($wpdb->last_error) {
            return new WP_Error('db_error', 'Database error fetching recent activity.', ['status' => 500, 'db_error' => $wpdb->last_error]);
        }

        $formatted_events = [];
        foreach ($results as $event) {
            $cardholder_name = trim($event['first_name'] . ' ' . $event['last_name']);
            if (empty($cardholder_name) && !empty($event['rfid_id']) && $event['rfid_id'] != 0) {
                $cardholder_name = 'Unknown Card (' . $event['rfid_id'] . ')';
            } elseif (empty($cardholder_name)) {
                $cardholder_name = 'System Event';
            }

            $formatted_events[] = [
                'logId'          => (int)$event['log_id'],
                'eventType'      => $event['access_granted'] ? 'accessGranted' : 'accessDenied',
                'cardholderName' => $cardholder_name,
                'cardholderId'   => !empty($event['cardholder_id']) ? (int)$event['cardholder_id'] : null,
                'photoURL'       => !empty($event['photo']) ? 'data:image/jpeg;base64,' . base64_encode($event['photo']) : '',
                'gateName'       => $event['gate_name'] ?: ($event['controller_name'] ?: 'Unknown Gate'),
                'timestamp'      => date('g:i:s A', strtotime($event['event_timestamp'])),
                'eventMessage'   => $event['event_description'],
                'cardNumber'     => (int)ltrim($event['rfid_id'], '0'),
                'controller_identifier' => $event['controller_identifier'],
                'doorRecordId'   => (int)$event['door_record_id'],
                'streetAddress'  => $event['street_address'] ?? 'N/A',
            ];
        }

        return new WP_REST_Response($formatted_events, 200);
    }
    
    // Helper function for building the recent activity query
    private function get_recent_activity_query() {
        global $wpdb;
        $log_table = 'ac_access_log';
        $cardholders_table = 'ac_cardholders';
        $doors_table = 'ac_doors';
        $controllers_table = 'ac_controllers';
        $property_table = 'ac_property';

        // It uses the database's internal clock, to get records in the last 24 hrs.
        $query = "SELECT l.log_id, l.event_timestamp, l.access_granted, 
            l.event_description, cred.credential_value AS rfid_id, 
            l.controller_identifier, ch.id AS cardholder_id, ch.first_name, ch.last_name, 
            ch.photo, d.friendly_name AS gate_name, c.friendly_name AS controller_name, d.door_record_id, p.street_address
            FROM {$log_table} AS l
            LEFT JOIN {$cardholders_table} AS ch ON l.cardholder_id = ch.id
            LEFT JOIN ac_credentials AS cred ON ch.id = cred.cardholder_id AND cred.credential_type = 'MIFARE_BADGE'
            LEFT JOIN {$controllers_table} AS c ON l.controller_identifier = c.uhppoted_device_id
            LEFT JOIN {$doors_table} AS d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller
            LEFT JOIN {$property_table} AS p ON ch.property_id = p.property_id
            WHERE l.event_timestamp >= NOW() - INTERVAL 24 HOUR
            ORDER BY l.event_timestamp DESC
            LIMIT 50";

        return $query;
    }

    /**
     *  Callback to manually set the control state of a single door.
     */
    public function set_door_state_callback( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $door_id = isset($params['door_id']) ? absint($params['door_id']) : 0;
        $state_code = isset($params['state']) ? absint($params['state']) : 0;

        if (empty($door_id) || !in_array($state_code, [1, 2, 3])) {
            return new WP_Error('bad_request', 'Invalid door ID or state provided.', ['status' => 400]);
        }

        // Instead of running CLI commands, broadcast the intent to the hardware plugins
        // They will return true if they successfully handled it, or a WP_Error on failure
        $result = apply_filters('fsbhoa_hardware_set_door_state', false, $door_id, $state_code);

        if ( is_wp_error($result) ) {
            return $result; // Pass the hardware plugin's specific error back to the UI
        } elseif ( $result === true ) {
            return new WP_REST_Response(['status' => 'success', 'message' => 'Command executed by hardware plugin.'], 200);
        } else {
            return new WP_Error('no_handler', 'No hardware plugin is configured to handle this door.', ['status' => 501]);
        }
    }
    
    /**
     * Get the status of doors for the group that is being monitored.
     * "Given the current time, would members of this group be able to swipe."
     */
    public function get_group_status_callback(WP_REST_Request $request) {
        $target_group_id = get_option('fsbhoa_monitor_status_group_id', 0);
        
        if (!$target_group_id) {
            return rest_ensure_response([]);
        }

        // Ask hardware plugins to calculate the status of their doors for this group
        $status_map = apply_filters('fsbhoa_hardware_group_status', [], $target_group_id);

        return rest_ensure_response($status_map);
    }

    /**
     * Callback to get the name of the currently active schedule for the monitor UI.
     */
    public function get_current_schedule_callback( WP_REST_Request $request ) {
        global $wpdb;

        // Fetch the active ID using your existing helper function
        $active_schedule_id = fsbhoa_get_active_schedule_id();

        if ( ! $active_schedule_id ) {
            return rest_ensure_response( array( 'schedule_name' => 'Unknown Schedule' ) );
        }

        // Query the schedule name.
        // Note: Assuming your schedules are in 'ac_schedules' with 'schedule_name'.
        // Adjust the table or column name below if your schema differs.
        $schedule_table = 'ac_schedules';

        $schedule_name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$schedule_table} WHERE schedule_id = %d",
            $active_schedule_id
        ) );

        // Fallback just in case the query fails or returns null
        if ( empty( $schedule_name ) ) {
            $schedule_name = 'Schedule ID: ' . $active_schedule_id;
        }

        return rest_ensure_response( array( 'schedule_name' => $schedule_name ) );
    }

    /**
     * Callback to fetch the rendered read-only cardholder summary card.
     */
    public function get_cardholder_summary_callback( WP_REST_Request $request ) {
        $cardholder_id = absint($request->get_param( 'cardholder_id' ) );
        if ( ! $cardholder_id ) {
            return new WP_Error( 'bad_request', 'Invalid cardholder ID.', array( 'status' => 400 ) );
        }

        $summary_view_file = FSBHOA_AC_PLUGIN_DIR . 'includes/view-cardholder-summary-card.php';
        if ( ! file_exists( $summary_view_file ) ) {
            return new WP_Error( 'missing_file', 'Summary card view file not found.', array( 'status' => 500 ) );
        }

        require_once $summary_view_file;

        $html = fsbhoa_render_cardholder_summary_card($cardholder_id, false );
        return new WP_REST_Response( array( 'html' => $html ), 200 );
    }

} // end of class


<?php
/**
 * REST routes for the vehicle access log (ac_vehicle_log).
 *
 * Core owns the vehicle log the way it owns the pedestrian log (ac_access_log): a vehicle
 * hardware plugin's service combines its inputs into one event per vehicle and posts it
 * here. The live monitor's vehicle column reads it back.
 *
 * POST /vehicle-event (services; Access Verification API Key as X-API-KEY). JSON body:
 *   environment        required; must match FSBHOA_AC_ENVIRONMENT ('testbed' or 'production')
 *   event_uid          sender's event ID (up to 64 chars); a repeated post is answered with
 *                      the stored row's ID and "duplicate": true, and isn't stored again
 *   event_timestamp    local time (site time zone) of the first input,
 *                      "YYYY-MM-DD HH:MM:SS[.ffffff]"; defaults to the time of the post
 *   gate_identifier    the lane or gate name shown on the monitor
 *   direction          'entry' (default) or 'exit': which way the lane goes
 *   auth_id            credential value or PIN presented at the gate
 *   auth_type          its ac_credentials.credential_type (e.g. DK_WINDSHIELD, DK_ENTRY_CODE)
 *   lpr_plate          plate text
 *   lpr_confidence     plate reader confidence, 0-100
 *   is_circumvention   true if the vehicle passed without a credential
 *   lpr_image_b64      plate photo, base64 JPEG
 *   context_image_b64  scene photo, base64 JPEG
 *   raw_details        any JSON object: the sender's inputs and timings
 * Replies { success, vehicle_log_id[, duplicate] }. 409 for the other environment's event,
 * 503 when this server's environment isn't set.
 *
 * GET /vehicle-recent[?since=<vehicle_log_id>] and GET /vehicle-image/<id>?type=lpr|context
 * are for the monitor page (logged-in admin, X-WP-Nonce or _wpnonce).
 *
 * @package Fsbhoa_Ac
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Vehicle_Log_REST_API {

    const TABLE = 'ac_vehicle_log';

    private $namespace = 'fsbhoa/v1';

    /**
     * Whether the live monitor shows the vehicle column (and its settings). Off unless a
     * vehicle hardware plugin turns it on through the fsbhoa_monitor_show_vehicle_column filter.
     */
    public static function show_monitor_column() {
        return (bool) apply_filters( 'fsbhoa_monitor_show_vehicle_column', false );
    }

    public function register_routes() {
        // Ingest one finished vehicle event from a vehicle hardware service
        register_rest_route( $this->namespace, '/vehicle-event', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'ingest_event' ),
            'permission_callback' => array( 'Fsbhoa_Verification_REST_API', 'api_key_permission_check' ), // Services send X-API-KEY
        ) );

        // Serve a stored plate or scene photo as JPEG
        register_rest_route( $this->namespace, '/vehicle-image/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'serve_image' ),
            'permission_callback' => array( $this, 'monitor_user_permission_check' ),
        ) );

        // Recent events for the monitor's vehicle column
        register_rest_route( $this->namespace, '/vehicle-recent', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_recent' ),
            'permission_callback' => array( $this, 'monitor_user_permission_check' ),
        ) );
    }

    /**
     * Monitor routes (event list and photos) are for logged-in admins, like core's monitor routes.
     * The page sends X-WP-Nonce on fetch() calls and _wpnonce on image URLs.
     */
    public function monitor_user_permission_check() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Store one vehicle event in ac_vehicle_log.
     */
    public function ingest_event( WP_REST_Request $request ) {
        global $wpdb;
        $table = self::TABLE;

        $params = $request->get_json_params();
        if ( empty( $params ) ) {
            return new WP_Error( 'invalid_json', 'No JSON payload supplied', array( 'status' => 400 ) );
        }

        // Fail closed: accept events only when this server's environment is known, and only
        // from a sender in the same environment (the testbed must never write production's log).
        $environment = defined( 'FSBHOA_AC_ENVIRONMENT' ) ? FSBHOA_AC_ENVIRONMENT : '';
        if ( ! in_array( $environment, array( 'testbed', 'production' ), true ) ) {
            error_log( 'FSBHOA Vehicle Log: refusing vehicle-event: FSBHOA_AC_ENVIRONMENT is not set to testbed or production.' );
            return new WP_Error( 'environment_not_set', 'This server\'s environment is not configured', array( 'status' => 503 ) );
        }
        $sent_environment = sanitize_text_field( $params['environment'] ?? '' );
        if ( $sent_environment !== $environment ) {
            error_log( sprintf( 'FSBHOA Vehicle Log: refusing vehicle-event from environment "%s" on %s.', $sent_environment, $environment ) );
            return new WP_Error( 'environment_mismatch', 'Event is from a different environment', array( 'status' => 409 ) );
        }

        // A retried post (the reply to the first one was lost) must not store the event twice
        $event_uid = sanitize_text_field( $params['event_uid'] ?? '' );
        if ( '' !== $event_uid ) {
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT vehicle_log_id FROM {$table} WHERE event_uid = %s", $event_uid ) );
            if ( $existing ) {
                return rest_ensure_response( array(
                    'success'        => true,
                    'vehicle_log_id' => (int) $existing,
                    'duplicate'      => true,
                ) );
            }
        }

        $context_blob = ! empty( $params['context_image_b64'] ) ? base64_decode( $params['context_image_b64'] ) : null;
        $lpr_blob     = ! empty( $params['lpr_image_b64'] ) ? base64_decode( $params['lpr_image_b64'] ) : null;

        // event_timestamp is local time (site time zone), like ac_access_log.
        $timestamp = current_time( 'mysql' );
        if ( ! empty( $params['event_timestamp'] ) ) {
            $sent = sanitize_text_field( $params['event_timestamp'] );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $sent ) ) {
                $timestamp = $sent;
            } else {
                error_log( 'FSBHOA Vehicle Log: ignoring malformed event_timestamp ' . $sent );
            }
        }

        $confidence  = isset( $params['lpr_confidence'] ) ? absint( $params['lpr_confidence'] ) : null;
        $raw_details = ! empty( $params['raw_details'] ) ? wp_json_encode( $params['raw_details'] ) : null;

        $data = array(
            'event_timestamp'    => $timestamp,
            'gate_identifier'    => sanitize_text_field( $params['gate_identifier'] ?? '' ),
            'direction'          => ( 'exit' === ( $params['direction'] ?? '' ) ) ? 'exit' : 'entry',
            'auth_id'            => sanitize_text_field( $params['auth_id'] ?? '' ),
            'auth_type'          => sanitize_text_field( $params['auth_type'] ?? '' ),
            'lpr_plate_string'   => sanitize_text_field( $params['lpr_plate'] ?? '' ),
            'lpr_confidence'     => $confidence,
            'is_circumvention'   => ! empty( $params['is_circumvention'] ) ? 1 : 0,
            'context_image_data' => $context_blob,
            'lpr_image_data'     => $lpr_blob,
            'raw_details'        => $raw_details,
            'event_uid'          => '' !== $event_uid ? $event_uid : null,
        );

        $formats  = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' );
        $inserted = $wpdb->insert( $table, $data, $formats );

        if ( false === $inserted ) {
            error_log( 'FSBHOA Vehicle Log: insert failed: ' . $wpdb->last_error );
            return new WP_Error( 'db_insert_error', 'Could not store the vehicle event', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array(
            'success'        => true,
            'vehicle_log_id' => $wpdb->insert_id,
        ) );
    }

    /**
     * Send a stored photo as JPEG: ?type=lpr for the plate, anything else for the scene.
     */
    public function serve_image( WP_REST_Request $request ) {
        global $wpdb;
        $log_id = absint( $request['id'] );
        $type   = $request->get_param( 'type' );

        $column = ( 'lpr' === $type ) ? 'lpr_image_data' : 'context_image_data';
        $table  = self::TABLE;

        $image_data = $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$table} WHERE vehicle_log_id = %d",
            $log_id
        ) );

        if ( empty( $image_data ) ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        header( 'Content-Type: image/jpeg' );
        header( 'Content-Length: ' . strlen( $image_data ) );
        header( 'Cache-Control: private, max-age=300' ); // Admin-only photos: no shared caches
        echo $image_data;
        exit;
    }

    /**
     * Recent events with the cardholder each credential belongs to: the last 24 hours on the
     * first load, then only rows after ?since=<vehicle_log_id>.
     */
    public function get_recent( WP_REST_Request $request ) {
        global $wpdb;
        $table = self::TABLE;
        $since = absint( $request->get_param( 'since' ) );

        $select_fields = "
                vl.vehicle_log_id,
                vl.event_timestamp,
                vl.gate_identifier,
                vl.direction,
                vl.auth_id,
                vl.auth_type,
                vl.lpr_plate_string,
                vl.lpr_confidence,
                vl.is_circumvention,
                (vl.context_image_data IS NOT NULL) AS has_context_img,
                (vl.lpr_image_data IS NOT NULL) AS has_lpr_img,
                COALESCE(
                    CASE WHEN vl.auth_type = 'DK_WINDSHIELD' THEN NULLIF(h.primary_cardholder_id, 0) END,
                    NULLIF(cred.cardholder_id, 0),
                    h.primary_cardholder_id,
                    0
                ) AS cardholder_id,
                COALESCE(
                    CASE WHEN vl.auth_type = 'DK_WINDSHIELD' THEN NULLIF(TRIM(CONCAT(c_primary.first_name, ' ', c_primary.last_name)), '') END,
                    NULLIF(TRIM(CONCAT(c_direct.first_name, ' ', c_direct.last_name)), ''),
                    NULLIF(TRIM(CONCAT(c_primary.first_name, ' ', c_primary.last_name)), ''),
                    ''
                ) AS cardholder_name,
                (SELECT COUNT(*) FROM ac_credentials cnt
                  WHERE cnt.credential_type = vl.auth_type
                    AND cnt.credential_value = vl.auth_id
                    AND cnt.status = 'active') AS credential_matches
        ";

        // A credential value can belong to more than one credential (shared household PINs,
        // reissued tags). Join only the best match so each event returns one row: an active
        // credential of an active cardholder first, then the oldest.
        $joins = "
                LEFT JOIN ac_credentials cred
                    ON cred.id = (
                        SELECT c2.id
                          FROM ac_credentials c2
                          LEFT JOIN ac_cardholders ch2 ON ch2.id = c2.cardholder_id
                         WHERE c2.credential_type = vl.auth_type
                           AND c2.credential_value = vl.auth_id
                         ORDER BY (c2.status = 'active') DESC,
                                  (ch2.cardholder_status = 'active') DESC,
                                  c2.id ASC
                         LIMIT 1
                    )
                LEFT JOIN ac_cardholders c_direct
                    ON c_direct.id = cred.cardholder_id
                LEFT JOIN ac_vehicles v
                    ON v.vehicle_id = cred.vehicle_id
                LEFT JOIN ac_households h
                    ON h.household_id = v.household_id
                LEFT JOIN ac_cardholders c_primary
                    ON c_primary.id = h.primary_cardholder_id
        ";

        if ( $since > 0 ) {
            $query = $wpdb->prepare(
                "SELECT " . $select_fields . "
                 FROM " . $table . " vl
                 " . $joins . "
                 WHERE vl.vehicle_log_id > %d
                 ORDER BY vl.vehicle_log_id ASC
                 LIMIT 100",
                $since
            );
        } else {
            $query = "SELECT " . $select_fields . "
                      FROM " . $table . " vl
                      " . $joins . "
                      WHERE vl.event_timestamp >= (NOW() - INTERVAL 24 HOUR)
                      ORDER BY vl.event_timestamp DESC
                      LIMIT 200";
        }

        $results = $wpdb->get_results( $query, ARRAY_A );

        if ( ! empty( $wpdb->last_error ) ) {
            error_log( 'FSBHOA Vehicle Log: vehicle-recent query failed: ' . $wpdb->last_error . ' -- ' . $wpdb->last_query );
            return new WP_Error( 'db_error', 'Could not load vehicle events', array( 'status' => 500 ) );
        }

        return rest_ensure_response( ! empty( $results ) ? $results : array() );
    }
}

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
 *   overview_image_b64 the lane overview camera's photo of the vehicle, base64 JPEG
 *   event_note         a short note shown on the vehicle's card (up to 100 characters), such
 *                      as "Arm already up 2 min 56 s"
 *   note_image_b64     a photo that goes with the note, base64 JPEG
 *   raw_details        any JSON object: the sender's inputs and timings
 *   alert_key          a lane alert instead of a vehicle: its kind (letters, digits, _ and -,
 *                      up to 32), such as arm_broken. One kind per lane is either on or off.
 *   alert_text         what the monitor shows for it ("Arm broken"), up to 100 characters
 *   alert_active       true when the alert starts, false when it ends
 *   alert_notice       true for a notice (such as the arm held up): shown in the list
 *                      when it starts and ends, without the alarm or acknowledgment
 * Replies { success, vehicle_log_id[, duplicate] }. 409 for the other environment's event,
 * 503 when this server's environment isn't set.
 *
 * GET /vehicle-recent[?since=<vehicle_log_id>], GET /vehicle-image/<id>?type=lpr|context|overview|note,
 * GET /vehicle-alerts (the lane alerts now on that nobody has acknowledged: each lane and kind
 * whose latest row has alert_active 1, alert_notice 0 and no alert_ack_at) and POST /vehicle-alerts/<id>/ack
 * (staff saw it: records who and when) are for the monitor page (logged-in admin, X-WP-Nonce
 * or _wpnonce). The monitor shows each alert from /vehicle-alerts as a pulsing red card at
 * the top of the vehicle column, with an alarm sound, a desktop notification and a flashing
 * tab title, on every screen, until someone acknowledges it or it ends.
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

        // Lane alerts that are on, for the monitor's banner
        register_rest_route( $this->namespace, '/vehicle-alerts', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_active_alerts' ),
            'permission_callback' => array( $this, 'monitor_user_permission_check' ),
        ) );

        // Staff acknowledge an alert: its banner and sound stop on every screen
        register_rest_route( $this->namespace, '/vehicle-alerts/(?P<id>\d+)/ack', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'acknowledge_alert' ),
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

        $context_blob  = ! empty( $params['context_image_b64'] ) ? base64_decode( $params['context_image_b64'] ) : null;
        $lpr_blob      = ! empty( $params['lpr_image_b64'] ) ? base64_decode( $params['lpr_image_b64'] ) : null;
        $overview_blob = ! empty( $params['overview_image_b64'] ) ? base64_decode( $params['overview_image_b64'] ) : null;
        $note_blob     = ! empty( $params['note_image_b64'] ) ? base64_decode( $params['note_image_b64'] ) : null;
        $event_note    = mb_substr( sanitize_text_field( $params['event_note'] ?? '' ), 0, 100 );

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

        // A lane alert (such as a broken arm) rather than a vehicle
        $alert_key    = null;
        $alert_text   = null;
        $alert_active = null;
        $alert_notice = 0;
        if ( ! empty( $params['alert_key'] ) ) {
            $alert_key = (string) $params['alert_key'];
            if ( ! preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $alert_key ) ) {
                return new WP_Error( 'invalid_alert', 'alert_key may have only letters, digits, _ and -, up to 32', array( 'status' => 400 ) );
            }
            $alert_text   = mb_substr( sanitize_text_field( $params['alert_text'] ?? '' ), 0, 100 );
            $alert_active = ! empty( $params['alert_active'] ) ? 1 : 0;
            $alert_notice = ! empty( $params['alert_notice'] ) ? 1 : 0;
        }

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
            'overview_image_data' => $overview_blob,
            'note_image_data'    => $note_blob,
            'event_note'         => '' !== $event_note ? $event_note : null,
            'raw_details'        => $raw_details,
            'event_uid'          => '' !== $event_uid ? $event_uid : null,
            'alert_key'          => $alert_key,
            'alert_text'         => $alert_text,
            'alert_active'       => $alert_active,
            'alert_notice'       => $alert_notice,
            // Whether each photo is there, so lists don't read the photos (MariaDB reads a
            // whole blob even for IS NOT NULL: 5 s for a day's rows)
            'has_context_img'    => ! empty( $context_blob ) ? 1 : 0,
            'has_lpr_img'        => ! empty( $lpr_blob ) ? 1 : 0,
            'has_overview_img'   => ! empty( $overview_blob ) ? 1 : 0,
            'has_note_img'       => ! empty( $note_blob ) ? 1 : 0,
        );

        $formats  = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d' );
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
     * Send a stored photo as JPEG: ?type=lpr for the plate, ?type=overview for the overview
     * camera's photo, ?type=note for the note's photo, anything else for the scene.
     */
    public function serve_image( WP_REST_Request $request ) {
        global $wpdb;
        $log_id = absint( $request['id'] );
        $type   = $request->get_param( 'type' );

        $columns = array(
            'lpr'      => 'lpr_image_data',
            'overview' => 'overview_image_data',
            'note'     => 'note_image_data',
        );
        $column  = $columns[ $type ] ?? 'context_image_data';
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
                vl.alert_key,
                vl.alert_text,
                vl.alert_active,
                vl.alert_notice,
                vl.alert_ack_at,
                vl.alert_ack_by,
                vl.has_context_img,
                vl.has_lpr_img,
                vl.has_overview_img,
                vl.event_note,
                vl.has_note_img,
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
                      LIMIT 5000";
        }

        $results = $wpdb->get_results( $query, ARRAY_A );

        if ( ! empty( $wpdb->last_error ) ) {
            error_log( 'FSBHOA Vehicle Log: vehicle-recent query failed: ' . $wpdb->last_error . ' -- ' . $wpdb->last_query );
            return new WP_Error( 'db_error', 'Could not load vehicle events', array( 'status' => 500 ) );
        }

        return rest_ensure_response( ! empty( $results ) ? $results : array() );
    }

    /**
     * The lane alerts that are on and not acknowledged: for each lane and alert kind, its
     * latest row, when that row started the alert and nobody has acknowledged it. Oldest first.
     */
    public function get_active_alerts( WP_REST_Request $request ) {
        global $wpdb;
        $table = self::TABLE;

        $results = $wpdb->get_results(
            "SELECT vl.vehicle_log_id, vl.event_timestamp, vl.gate_identifier, vl.alert_key, vl.alert_text,
                    vl.has_overview_img
               FROM {$table} vl
               JOIN ( SELECT MAX(vehicle_log_id) AS id
                        FROM {$table}
                       WHERE alert_key IS NOT NULL
                       GROUP BY gate_identifier, alert_key ) latest ON latest.id = vl.vehicle_log_id
              WHERE vl.alert_active = 1
                AND vl.alert_notice = 0
                AND vl.alert_ack_at IS NULL
              ORDER BY vl.vehicle_log_id ASC",
            ARRAY_A
        );

        if ( ! empty( $wpdb->last_error ) ) {
            error_log( 'FSBHOA Vehicle Log: vehicle-alerts query failed: ' . $wpdb->last_error );
            return new WP_Error( 'db_error', 'Could not load vehicle alerts', array( 'status' => 500 ) );
        }

        return rest_ensure_response( ! empty( $results ) ? $results : array() );
    }

    /**
     * Record that staff acknowledged an alert (the first acknowledgment counts).
     */
    public function acknowledge_alert( WP_REST_Request $request ) {
        global $wpdb;
        $table  = self::TABLE;
        $log_id = absint( $request['id'] );
        $user   = wp_get_current_user();
        $by     = mb_substr( $user->display_name ? $user->display_name : $user->user_login, 0, 60 );
        $now    = current_time( 'mysql' );

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET alert_ack_at = %s, alert_ack_by = %s
              WHERE vehicle_log_id = %d AND alert_key IS NOT NULL AND alert_ack_at IS NULL",
            $now, $by, $log_id
        ) );
        if ( false === $updated ) {
            error_log( 'FSBHOA Vehicle Log: alert acknowledgment failed: ' . $wpdb->last_error );
            return new WP_Error( 'db_error', 'Could not acknowledge the alert', array( 'status' => 500 ) );
        }
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT alert_ack_at, alert_ack_by FROM {$table} WHERE vehicle_log_id = %d AND alert_key IS NOT NULL",
            $log_id
        ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'No such alert', array( 'status' => 404 ) );
        }
        return rest_ensure_response( array( 'success' => true ) + $row );
    }
}

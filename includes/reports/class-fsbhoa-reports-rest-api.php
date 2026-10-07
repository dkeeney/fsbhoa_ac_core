<?php
/**
 * Handles REST API endpoints for the Reports component.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Reports_REST_API {

    private $namespace = 'fsbhoa/v1';

    public function register_routes() {
        register_rest_route( $this->namespace, '/reports/access-log', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'get_access_log_callback' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ) );

        register_rest_route( $this->namespace, '/reports/usage-analytics', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_usage_analytics_callback' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ) );

        register_rest_route( $this->namespace, '/reports/daily-summary', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_daily_summary_callback' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ) );
    }

    public function get_access_log_callback( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_params();

        $draw    = isset( $params['draw'] ) ? absint( $params['draw'] ) : 1;
        $start   = isset( $params['start'] ) ? absint( $params['start'] ) : 0;
        $length  = isset( $params['length'] ) ? absint( $params['length'] ) : 100;
        $search  = isset( $params['search']['value'] ) ? sanitize_text_field( $params['search']['value'] ) : '';
        $order_col_index = isset( $params['order'][0]['column'] ) ? absint( $params['order'][0]['column'] ) : 0;
        $order_dir = isset( $params['order'][0]['dir'] ) && strtolower($params['order'][0]['dir']) === 'asc' ? 'ASC' : 'DESC';
        $start_date = isset($params['start_date']) ? sanitize_text_field($params['start_date']) : '';
        $end_date   = isset($params['end_date']) ? sanitize_text_field($params['end_date']) : '';
        $gate_id    = isset($params['gate_id']) ? absint($params['gate_id']) : 0;
        $show_photo = isset($params['show_photo']) && $params['show_photo'] === 'true';

        $base_query = " FROM ac_access_log l LEFT JOIN ac_cardholders ch ON l.cardholder_id = ch.id LEFT JOIN ac_property p ON ch.property_id = p.property_id LEFT JOIN ac_controllers c ON l.controller_identifier = c.uhppoted_device_id LEFT JOIN ac_doors d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller ";
        $where_clauses = [];
        if ( ! empty($start_date) ) { $where_clauses[] = $wpdb->prepare( "l.event_timestamp >= %s", date( 'Y-m-d 00:00:00', strtotime( $start_date ) ) ); }
        if ( ! empty($end_date) ) {
            $end_date_obj = new DateTime($end_date);
            $end_date_obj->modify('+1 day');
            $next_day_start = $end_date_obj->format('Y-m-d 00:00:00');
            $where_clauses[] = $wpdb->prepare( "l.event_timestamp < %s", $next_day_start );
        }
        if ( ! empty($gate_id) ) { $where_clauses[] = $wpdb->prepare( "d.door_record_id = %d", $gate_id ); }
        if ( ! empty($search) ) { $search_term = '%' . $wpdb->esc_like( $search ) . '%'; $where_clauses[] = $wpdb->prepare( "(CONCAT(ch.first_name, ' ', ch.last_name) LIKE %s OR p.street_address LIKE %s OR d.friendly_name LIKE %s OR l.event_description LIKE %s OR l.rfid_id LIKE %s)", $search_term, $search_term, $search_term, $search_term, $search_term ); }
        $where_sql = ! empty( $where_clauses ) ? " WHERE " . implode( ' AND ', $where_clauses ) : '';

        $records_total = $wpdb->get_var( "SELECT COUNT(l.log_id) {$base_query}" );
        if ( $wpdb->last_error ) { return new WP_Error( 'db_error', 'Database error counting total records.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) ); }

        $records_filtered = $wpdb->get_var( "SELECT COUNT(l.log_id) {$base_query} {$where_sql}" );
        if ( $wpdb->last_error ) { return new WP_Error( 'db_error', 'Database error counting filtered records.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) ); }
        
        $columns = [ 'l.event_timestamp', 'l.event_timestamp', "CONCAT(ch.first_name, ' ', ch.last_name)", 'ch.resident_type', 'p.street_address', 'd.friendly_name', 'l.access_granted', 'l.event_description' ];
        $order_by_col = $columns[$order_col_index] ?? $columns[0];

        $data_query = " SELECT l.log_id, l.controller_identifier, ch.id AS cardholder_id, ch.photo, l.rfid_id, l.event_timestamp, CONCAT(ch.first_name, ' ', ch.last_name) as cardholder, ch.resident_type, p.street_address as property, d.friendly_name as gate_name, c.friendly_name as controller_name, l.access_granted, l.event_description {$base_query} {$where_sql} ORDER BY {$order_by_col} {$order_dir} LIMIT %d OFFSET %d ";
        $results = $wpdb->get_results( $wpdb->prepare( $data_query, $length, $start ), ARRAY_A );
        if ( $wpdb->last_error ) { return new WP_Error( 'db_error', 'Database error fetching report data.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) ); }
        
        $data = [];
        foreach ( $results as $row ) {
            $row['event_timestamp'] = date('Y-m-d H:i:s', strtotime($row['event_timestamp']));
            $row['photo'] = $show_photo && !empty($row['photo']) ? base64_encode($row['photo']) : null;
            $granted = $row['access_granted'];
            $row['access_granted'] = is_null($granted) ? '—' : ($granted ? '<span class="access-granted">Granted</span>' : '<span class="access-denied">Denied</span>');
            $resident_type = $row['resident_type'];
            if ( $resident_type === 'Resident Owner' ) { $row['resident_type'] = 'O'; } elseif ( !empty($resident_type) ) { $row['resident_type'] = strtoupper(substr($resident_type, 0, 1)); } else { $row['resident_type'] = ''; }
            if ( $row['cardholder'] ) {
                $row['cardholder'] = esc_html($row['cardholder']);
            } elseif ( !empty($row['rfid_id']) && $row['rfid_id'] != 0 ) {
                // Keep the number of an unrecognized card so it can be looked up.
                $row['cardholder'] = '<em>Unknown Card (' . esc_html($row['rfid_id']) . ')</em>';
            } else {
                $row['cardholder'] = '<em>Event/No Card</em>';
            }
            // Door name, else the controller's name (no matching door), else the raw identifier
            // (e.g. older kiosk rows logged as 'kiosk').
            if ($row['gate_name']) {
                $row['gate_name'] = esc_html($row['gate_name']);
            } elseif ($row['controller_name']) {
                $row['gate_name'] = esc_html($row['controller_name']);
            } elseif ($row['controller_identifier'] !== '') {
                $row['gate_name'] = '<em>' . esc_html($row['controller_identifier']) . '</em>';
            } else {
                $row['gate_name'] = '<em>Unknown Gate</em>';
            }
            $row['property'] = $row['property'] ? esc_html($row['property']) : '';
            $row['property'] = $row['property'] ? esc_html($row['property']) : '';
            $data[] = $row;
        }

        $response = [ 'draw' => $draw, 'recordsTotal' => $records_total, 'recordsFiltered' => $records_filtered, 'data' => $data ];
        return new WP_REST_Response( $response, 200 );
    }

public function get_usage_analytics_callback( WP_REST_Request $request ) {
        global $wpdb;

        $year = $request->get_param('year') ? absint($request->get_param('year')) : date('Y');
        $month = $request->get_param('month') ? absint($request->get_param('month')) : date('m');

        $response_data = [
            'gateUsage'   => [],
            'hourlyUsage' => array_fill(0, 24, 0),
            'amenityUsage' => [],
        ];

        // --- Gate Usage Query ---
        // 1. Removed "Card swipe%" filter to include "Amenity:" and "Access:" events.
        // 2. Label by door name, else controller name, else the raw identifier (same as the
        //    Access Log report). Each kiosk station is counted under its own name.
        $gate_results = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                COALESCE(d.friendly_name, c.friendly_name, NULLIF(l.controller_identifier, ''), 'Unknown Gate') as friendly_name, 
                COUNT(l.log_id) as count
            FROM ac_access_log l
            LEFT JOIN ac_controllers c ON l.controller_identifier = c.uhppoted_device_id
            LEFT JOIN ac_doors d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller
            WHERE l.access_granted = 1 
              AND YEAR(l.event_timestamp) = %d 
              AND MONTH(l.event_timestamp) = %d
            GROUP BY 
                COALESCE(d.friendly_name, c.friendly_name, NULLIF(l.controller_identifier, ''), 'Unknown Gate')
            ORDER BY COUNT(l.log_id) DESC",
            $year,
            $month
        ) );
        if ( ! $wpdb->last_error ) {
            $response_data['gateUsage'] = $gate_results;
        }

        // --- Hourly Usage Query  ---
        $hourly_results = $wpdb->get_results( $wpdb->prepare(
            "SELECT HOUR(l.event_timestamp) as hour, SUM(l.guest_count + 1) as count
            FROM ac_access_log l
            WHERE (l.event_description LIKE 'Card swipe%%' OR l.event_description LIKE 'Amenity: %%') AND YEAR(l.event_timestamp) = %d AND MONTH(l.event_timestamp) = %d
            GROUP BY HOUR(l.event_timestamp)",
            $year,
            $month
        ) );
        if ( ! $wpdb->last_error ) {
            foreach ($hourly_results as $result) {
                $response_data['hourlyUsage'][$result->hour] = (int)$result->count;
            }
        }
        
        // --- Amenity Usage Query  ---
        $amenity_query = $wpdb->prepare("
            SELECT
                al.amenity_name AS amenity_name_clean,
                -- Calculate the total number of people: 1 (cardholder) + guest_count
                SUM(1 + al.guest_count) AS count
            FROM
                ac_access_log al
            WHERE
                -- 1. Filters only for entries that have a confirmed amenity name (NULL means cleared/canceled)
                al.amenity_name IS NOT NULL
                -- 2. Only count granted access events (our log logic runs on granted access only, but good practice)
                AND al.access_granted = 1
                -- 3. Filter by the specific month and year requested by the front-end
                AND YEAR(al.event_timestamp) = %d 
                AND MONTH(al.event_timestamp) = %d
            GROUP BY
                al.amenity_name
            ORDER BY
                count DESC
        ", $year, $month);

        $amenity_results = $wpdb->get_results( $amenity_query, ARRAY_A );

        if ( ! $wpdb->last_error ) {
            // NOTE: The PHP now returns 'amenity_name_clean' and 'count' which matches the JS map.
            $response_data['amenityUsage'] = $amenity_results;
        }

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Returns a summary of usage (Total People) for Today, Yesterday, and the selected Month.
     */
    public function get_daily_summary_callback( WP_REST_Request $request ) {
        $year = $request->get_param('year') ? absint($request->get_param('year')) : date('Y');
        $month = $request->get_param('month') ? absint($request->get_param('month')) : date('m');

        // 1. Get Data for "Today"
        $today_start = current_time('Y-m-d 00:00:00');
        $today_end   = current_time('Y-m-d 23:59:59');
        $today_data  = $this->fetch_amenity_counts($today_start, $today_end);

        // 2. Get Data for "Yesterday"
        $yesterday_start = date('Y-m-d 00:00:00', strtotime('-1 day', current_time('timestamp')));
        $yesterday_end   = date('Y-m-d 23:59:59', strtotime('-1 day', current_time('timestamp')));
        $yesterday_data  = $this->fetch_amenity_counts($yesterday_start, $yesterday_end);

        // 3. Get Data for "Selected Month"
        // Create date range for the entire requested month
        $month_start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $month_end   = date('Y-m-t 23:59:59', strtotime($month_start));
        $month_data  = $this->fetch_amenity_counts($month_start, $month_end);

        // 4. Merge Data
        // We want a list of ALL amenities that appear in any of the three lists.
        $all_amenities = array_unique(array_merge(
            array_keys($today_data),
            array_keys($yesterday_data),
            array_keys($month_data)
        ));
        sort($all_amenities); // Alphabetical order

        $summary = [];
        $totals = ['amenity' => 'TOTALS', 'today' => 0, 'yesterday' => 0, 'month' => 0];

        foreach ($all_amenities as $amenity) {
            $t = $today_data[$amenity] ?? 0;
            $y = $yesterday_data[$amenity] ?? 0;
            $m = $month_data[$amenity] ?? 0;

            $summary[] = [
                'amenity'   => $amenity,
                'today'     => $t,
                'yesterday' => $y,
                'month'     => $m,
            ];

            // Calculate Grand Totals
            $totals['today'] += $t;
            $totals['yesterday'] += $y;
            $totals['month'] += $m;
        }

        // Add Totals row at the bottom
        if (!empty($summary)) {
            $summary[] = $totals;
        }

        return new WP_REST_Response([
            'summary' => $summary,
            'meta' => [
                'today_label' => 'Today ' . date('M j', strtotime($today_start)),
                'yesterday_label' => 'Yesterday ' . date('M j', strtotime($yesterday_start)),
                'month_label' => 'Month ' . date('F Y', strtotime($month_start))
            ]
        ], 200);
    }

    /**
     * Helper: Counts total people (Cardholder + Guests) per amenity for a date range.
     * Logic: 
     * - Access Granted Only
     * - Amenity Name IS NOT NULL (Deduplicates Entry Gate swipes)
     */
    private function fetch_amenity_counts($start_date, $end_date) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                amenity_name, 
                SUM(1 + guest_count) as total_people
            FROM ac_access_log 
            WHERE 
                access_granted = 1 
                AND amenity_name IS NOT NULL 
                AND event_timestamp BETWEEN %s AND %s
            GROUP BY amenity_name
        ", $start_date, $end_date));

        $data = [];
        foreach ($results as $row) {
            $data[$row->amenity_name] = (int)$row->total_people;
        }
        return $data;
    }

}

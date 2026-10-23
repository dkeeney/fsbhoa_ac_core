<?php
/**
 * The Vehicle Log report ([fsbhoa_vehicle_report]): ac_vehicle_log as a searchable table, the
 * way the Access Log report shows ac_access_log. Each row carries what the live monitor's
 * vehicle card shows: time, lane (EXIT), plate (or NO PLATE, NO PLATE READ, or the lane
 * alert's text), credential, cardholder, note and the photos (plate, gate, note). Photos older
 * than the Vehicle Photo Retention are purged; their places stay blank.
 *
 * POST /reports/vehicle-log (DataTables server side; logged-in admin) and the CSV exports
 * (admin-post fsbhoa_export_vehicle_log for the filtered rows, fsbhoa_export_selected_vehicle_logs
 * for the selected ones).
 *
 * @package Fsbhoa_Ac
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Vehicle_Report {

    private $namespace = 'fsbhoa/v1';

    // Sortable table columns by DataTables index; the others sort by time
    private const ORDER_COLUMNS = array(
        1 => 'vl.event_timestamp',
        3 => 'vl.lpr_plate_string',
        4 => 'vl.gate_identifier',
        5 => 'vl.auth_id',
        6 => 'cardholder_name',
    );

    private const CSV_HEADER = array( 'Timestamp', 'Lane', 'Direction', 'Plate', 'Credential Type', 'Credential', 'Cardholder', 'Note', 'Alert', 'Without Credential' );

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'admin_post_fsbhoa_export_vehicle_log', array( $this, 'handle_export' ) );
        add_action( 'admin_post_fsbhoa_export_selected_vehicle_logs', array( $this, 'handle_export_selected' ) );
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/reports/vehicle-log', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'get_vehicle_log_callback' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ) );
    }

    /**
     * The lanes for the Lane filter: the vehicle hardware plugin's lanes first, in its order,
     * then any other name in the log (lanes renamed or removed since).
     */
    private static function lanes() {
        global $wpdb;
        $lanes = Fsbhoa_Vehicle_Log_REST_API::monitor_lanes();
        $logged = $wpdb->get_col( "SELECT DISTINCT gate_identifier FROM " . Fsbhoa_Vehicle_Log_REST_API::TABLE . " WHERE gate_identifier <> '' ORDER BY gate_identifier" );
        foreach ( (array) $logged as $name ) {
            if ( ! in_array( $name, $lanes, true ) ) {
                $lanes[] = $name;
            }
        }
        return $lanes;
    }

    /**
     * The WHERE clause for the filters (each optional): start_date and end_date (YYYY-MM-DD,
     * whole days, local time), lane, kind ('vehicles' or 'alerts') and search (plate, lane,
     * credential, cardholder, note or alert text).
     */
    private static function where_sql( $filters ) {
        global $wpdb;
        $where = array();
        foreach ( array( 'start_date' => '>=', 'end_date' => '<' ) as $key => $op ) {
            $date = isset( $filters[ $key ] ) ? sanitize_text_field( $filters[ $key ] ) : '';
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                $day = new DateTime( $date, wp_timezone() );
                if ( '<' === $op ) {
                    $day->modify( '+1 day' );
                }
                $where[] = $wpdb->prepare( "vl.event_timestamp {$op} %s", $day->format( 'Y-m-d 00:00:00' ) );
            }
        }
        $lane = isset( $filters['lane'] ) ? sanitize_text_field( $filters['lane'] ) : '';
        if ( '' !== $lane ) {
            $where[] = $wpdb->prepare( 'vl.gate_identifier = %s', $lane );
        }
        $kind = isset( $filters['kind'] ) ? $filters['kind'] : '';
        if ( 'vehicles' === $kind ) {
            $where[] = "(vl.alert_key IS NULL OR vl.alert_key = '')";
        } elseif ( 'alerts' === $kind ) {
            $where[] = "vl.alert_key <> ''";
        }
        $search = isset( $filters['search'] ) ? sanitize_text_field( $filters['search'] ) : '';
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = $wpdb->prepare(
                "(vl.lpr_plate_string LIKE %s OR vl.gate_identifier LIKE %s OR vl.auth_id LIKE %s
                  OR CONCAT(c_direct.first_name, ' ', c_direct.last_name) LIKE %s
                  OR CONCAT(c_primary.first_name, ' ', c_primary.last_name) LIKE %s
                  OR vl.event_note LIKE %s OR vl.alert_text LIKE %s)",
                $like, $like, $like, $like, $like, $like, $like
            );
        }
        return $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
    }

    private static function select_sql() {
        return "SELECT vl.vehicle_log_id, vl.event_timestamp, vl.gate_identifier, vl.direction,
                vl.auth_id, vl.auth_type, vl.lpr_plate_string, vl.is_circumvention,
                vl.alert_key, vl.alert_text, vl.alert_active, vl.alert_notice, vl.alert_ack_at, vl.alert_ack_by,
                vl.event_note, vl.has_context_img, vl.has_lpr_img, vl.has_overview_img, vl.has_note_img,
                " . Fsbhoa_Vehicle_Log_REST_API::cardholder_fields_sql() . "
                FROM " . Fsbhoa_Vehicle_Log_REST_API::TABLE . " vl " . Fsbhoa_Vehicle_Log_REST_API::credential_joins_sql();
    }

    public function get_vehicle_log_callback( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_params();
        $table  = Fsbhoa_Vehicle_Log_REST_API::TABLE;

        $draw   = isset( $params['draw'] ) ? absint( $params['draw'] ) : 1;
        $start  = isset( $params['start'] ) ? absint( $params['start'] ) : 0;
        $length = isset( $params['length'] ) ? min( absint( $params['length'] ), 1000 ) : 100;
        $order_col = isset( $params['order'][0]['column'] ) ? absint( $params['order'][0]['column'] ) : 1;
        $order_dir = isset( $params['order'][0]['dir'] ) && 'asc' === strtolower( $params['order'][0]['dir'] ) ? 'ASC' : 'DESC';
        $order_by  = self::ORDER_COLUMNS[ $order_col ] ?? 'vl.event_timestamp';

        $filters = array(
            'start_date' => $params['start_date'] ?? '',
            'end_date'   => $params['end_date'] ?? '',
            'lane'       => $params['lane'] ?? '',
            'kind'       => $params['kind'] ?? '',
            'search'     => $params['search']['value'] ?? '',
        );
        $where_sql = self::where_sql( $filters );

        $records_total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        // The cardholder joins only when the search may match a name
        $count_from = '' !== $filters['search']
            ? "{$table} vl " . Fsbhoa_Vehicle_Log_REST_API::credential_joins_sql()
            : "{$table} vl";
        $records_filtered = $wpdb->get_var( "SELECT COUNT(*) FROM {$count_from} {$where_sql}" );
        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error counting vehicle log records.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            self::select_sql() . " {$where_sql} ORDER BY {$order_by} {$order_dir}, vl.vehicle_log_id {$order_dir} LIMIT %d OFFSET %d",
            $length,
            $start
        ), ARRAY_A );
        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error fetching the vehicle log.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }

        return new WP_REST_Response( array(
            'draw'            => $draw,
            'recordsTotal'    => (int) $records_total,
            'recordsFiltered' => (int) $records_filtered,
            'data'            => $rows,
        ), 200 );
    }

    /**
     * One CSV line per row, in CSV_HEADER's order.
     */
    private static function csv_row( $row ) {
        // An alert's end has its own text ("Arm OK again")
        $alert = ! empty( $row['alert_key'] ) ? ( $row['alert_text'] ?: $row['alert_key'] ) : '';
        return array(
            $row['event_timestamp'],
            $row['gate_identifier'],
            $row['direction'],
            $row['lpr_plate_string'],
            $row['auth_type'],
            $row['auth_id'],
            $row['cardholder_name'],
            $row['event_note'],
            $alert,
            $row['is_circumvention'] ? 'Yes' : '',
        );
    }

    private static function send_csv( $filename, $rows ) {
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, self::CSV_HEADER );
        foreach ( $rows as $row ) {
            fputcsv( $output, self::csv_row( $row ) );
        }
        fclose( $output );
        die();
    }

    /**
     * CSV of every row the filters match.
     */
    public function handle_export() {
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'fsbhoa_export_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }
        global $wpdb;
        $rows = $wpdb->get_results( self::select_sql() . self::where_sql( wp_unslash( $_GET ) ) . ' ORDER BY vl.event_timestamp DESC', ARRAY_A );
        if ( $wpdb->last_error ) {
            wp_die( 'Database error generating export: ' . esc_html( $wpdb->last_error ) );
        }
        self::send_csv( 'vehicle-log-' . current_time( 'Y-m-d' ) . '.csv', $rows );
    }

    /**
     * CSV of the rows selected in the table.
     */
    public function handle_export_selected() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'fsbhoa_export_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }
        $ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( $_POST['log_ids'] ?? '' ) ) ) );
        if ( empty( $ids ) ) {
            wp_die( 'No log entries were selected for export.' );
        }
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( self::select_sql() . " WHERE vl.vehicle_log_id IN ($placeholders) ORDER BY vl.event_timestamp DESC", $ids ), ARRAY_A );
        if ( $wpdb->last_error ) {
            wp_die( 'Database error generating export: ' . esc_html( $wpdb->last_error ) );
        }
        self::send_csv( 'vehicle-log-selected-' . current_time( 'Y-m-d' ) . '.csv', $rows );
    }

    public static function render_page() {
        ?>
        <div class="wrap fsbhoa-frontend-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Vehicle Log', 'fsbhoa-ac' ); ?></h1>
            <hr class="wp-header-end">

            <div class="fsbhoa-filter-container">
                <div class="filter-group">
                    <label for="vehicle_start_date"><?php esc_html_e( 'Start Date:', 'fsbhoa-ac' ); ?></label>
                    <input type="date" id="vehicle_start_date" class="fsbhoa-short-date">
                </div>
                <div class="filter-group">
                    <label for="vehicle_end_date"><?php esc_html_e( 'End Date:', 'fsbhoa-ac' ); ?></label>
                    <input type="date" id="vehicle_end_date" class="fsbhoa-short-date">
                </div>
                <div class="filter-group">
                    <label for="vehicle_lane"><?php esc_html_e( 'Lane:', 'fsbhoa-ac' ); ?></label>
                    <select id="vehicle_lane">
                        <option value=""><?php esc_html_e( 'All Lanes', 'fsbhoa-ac' ); ?></option>
                        <?php foreach ( self::lanes() as $lane ) : ?>
                        <option value="<?php echo esc_attr( $lane ); ?>"><?php echo esc_html( $lane ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="vehicle_kind"><?php esc_html_e( 'Show:', 'fsbhoa-ac' ); ?></label>
                    <select id="vehicle_kind">
                        <option value=""><?php esc_html_e( 'Everything', 'fsbhoa-ac' ); ?></option>
                        <option value="vehicles"><?php esc_html_e( 'Vehicles', 'fsbhoa-ac' ); ?></option>
                        <option value="alerts"><?php esc_html_e( 'Alerts and notices', 'fsbhoa-ac' ); ?></option>
                    </select>
                </div>
                <div class="filter-group search-group">
                    <label for="vehicle-live-search"><?php esc_html_e( 'Live Search:', 'fsbhoa-ac' ); ?></label>
                    <input type="text" id="vehicle-live-search" placeholder="Plate, lane, credential, cardholder, note...">
                </div>
                <div class="filter-group checkbox-group">
                    <label for="vehicle-show-photos">Photos</label>
                    <input type="checkbox" id="vehicle-show-photos" checked>
                </div>
                <div class="filter-group">
                    <button id="vehicle-clear-filters" class="button"><?php esc_html_e( 'Clear', 'fsbhoa-ac' ); ?></button>
                </div>
                <div class="filter-group page-length-group">
                    <label for="vehicle-page-length">Show</label>
                    <select id="vehicle-page-length">
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                        <option value="1000">1000</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button id="vehicle-export-button" class="button button-primary"><?php esc_html_e( 'Export to CSV', 'fsbhoa-ac' ); ?></button>
                </div>
            </div>

            <table id="fsbhoa-vehicle-log-table" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th class="no-sort select-checkbox" style="width: 10px;"></th>
                        <th><?php esc_html_e( 'Date & Time', 'fsbhoa-ac' ); ?></th>
                        <th class="no-sort vehicle-photos-column"><?php esc_html_e( 'Photos', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Plate', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Lane', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Credential', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Cardholder', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Note', 'fsbhoa-ac' ); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <!-- A photo at full size (openVehiclePhoto() in fsbhoa-vehicle-report.js) -->
            <div id="fsbhoa-vehicle-report-modal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 99999; justify-content: center; align-items: center; padding: 20px;" onclick="this.style.display='none';">
                <div style="max-width: 90vw; max-height: 90vh; text-align: center;" onclick="event.stopPropagation();">
                    <img id="fsbhoa-vehicle-report-modal-img" src="" alt="Photo" style="max-width: 100%; max-height: 80vh; border-radius: 8px;">
                    <div style="margin-top: 10px;">
                        <button type="button" class="button" onclick="document.getElementById('fsbhoa-vehicle-report-modal').style.display='none';">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

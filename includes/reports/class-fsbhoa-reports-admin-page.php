<?php
/**
 * Creates the admin page for viewing access control reports.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Reports_Admin_Page {

    /**
     * Handles the display of the reports page in the admin.
     */
    public function render_page() {
        ?>
        <div class="wrap fsbhoa-frontend-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Access Log', 'fsbhoa-ac' ); ?></h1>
            <hr class="wp-header-end">

            <div id="fsbhoa-reports-content">
                <div id="access-log" class="fsbhoa-tab-content">
                    <div class="fsbhoa-filter-container">
                        <div class="filter-group">
                            <label for="start_date"><?php esc_html_e( 'Start Date:', 'fsbhoa-ac' ); ?></label>
                            <input type="text" id="start_date" name="start_date" class="fsbhoa-datepicker fsbhoa-short-date">
                        </div>
                        <div class="filter-group">
                            <label for="end_date"><?php esc_html_e( 'End Date:', 'fsbhoa-ac' ); ?></label>
                            <input type="text" id="end_date" name="end_date" class="fsbhoa-datepicker fsbhoa-short-date">
                        </div>
                        <div class="filter-group">
                            <?php $this->render_gate_dropdown(); ?>
                        </div>
                        <div class="filter-group search-group">
                            <label for="fsbhoa-live-search"><?php esc_html_e( 'Live Search:', 'fsbhoa-ac' ); ?></label>
                            <input type="text" id="fsbhoa-live-search" placeholder="Search across all fields...">
                        </div>
                         <div class="filter-group checkbox-group">
                            <label for="show-photo">Photo</label>
                            <input type="checkbox" id="show-photo">
                        </div>
                        <div class="filter-group">
                             <button id="fsbhoa-clear-filters" class="button"><?php esc_html_e( 'Clear', 'fsbhoa-ac' ); ?></button>
                        </div>
                        <div class="filter-group page-length-group">
                           <label for="fsbhoa-page-length">Show</label>
                           <select id="fsbhoa-page-length" name="fsbhoa-page-length">
                               <option value="100">100</option>
                               <option value="200">200</option>
                               <option value="500">500</option>
                               <option value="1000">1000</option>
                           </select>
                        </div>
                        <div class="filter-group">
                           <div class="filter-group">
                                <button id="fsbhoa-export-button" class="button button-primary"><?php esc_html_e( 'Export to CSV', 'fsbhoa-ac' ); ?></button>
                            </div>
                        </div>
                    </div>

                    <table id="fsbhoa-access-log-table" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th class="no-sort select-checkbox" style="width: 10px;"></th>
                                <th class="datetime-column"><?php esc_html_e( 'Date & Time', 'fsbhoa-ac' ); ?></th>
                                <th class="no-sort photo-column"><?php esc_html_e( 'Photo', 'fsbhoa-ac' ); ?></th>
                                <th><?php esc_html_e( 'Cardholder', 'fsbhoa-ac' ); ?></th>
                                <th class="type-column"><?php esc_html_e( 'T', 'fsbhoa-ac' ); ?></th>
                                <th><?php esc_html_e( 'Property', 'fsbhoa-ac' ); ?></th>
                                <th><?php esc_html_e( 'Gate', 'fsbhoa-ac' ); ?></th>
                                <th><?php esc_html_e( 'Result', 'fsbhoa-ac' ); ?></th>
                                <th><?php esc_html_e( 'Description', 'fsbhoa-ac' ); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders a dropdown of gates.
     */
    private function render_gate_dropdown() {
        global $wpdb;
        $table_name = 'ac_doors';
        $gates = $wpdb->get_results("SELECT door_record_id, friendly_name FROM {$table_name} ORDER BY friendly_name");

        echo '<label for="gate_id">' . esc_html__( 'Gate:', 'fsbhoa-ac' ) . '</label>';
        echo '<select id="gate_id" name="gate_id">';
        echo '<option value="">' . esc_html__( 'All Gates', 'fsbhoa-ac' ) . '</option>';
        if ($gates) {
            foreach ($gates as $gate) {
                printf(
                    '<option value="%d">%s</option>',
                    esc_attr($gate->door_record_id),
                    esc_html($gate->friendly_name)
                );
            }
        }
        echo '</select>';
    }
}


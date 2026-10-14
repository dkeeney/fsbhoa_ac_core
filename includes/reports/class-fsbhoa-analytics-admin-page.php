<?php
/**
 * Creates the admin page for viewing usage analytics reports.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Analytics_Admin_Page {

    /**
     * Handles the display of the analytics page.
     */
    public function render_page() {
        ?>
        <div class="wrap fsbhoa-frontend-wrap">
            <div id="usage-analytics" class="fsbhoa-tab-content">
                <div class="fsbhoa-filter-container">
                     <div class="filter-group">
                        <label for="analytics-month">Month</label>
                        <select id="analytics-month">
                            <?php
                            $current_month = current_time('m');
                            for ($i = 1; $i <= 12; $i++) {
                                $month_val = str_pad($i, 2, '0', STR_PAD_LEFT);
                                $month_name = date('F', mktime(0, 0, 0, $i, 10));
                                echo '<option value="' . esc_attr($month_val) . '" ' . selected($current_month, $month_val, false) . '>' . esc_html($month_name) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="analytics-year">Year</label>
                        <select id="analytics-year">
                            <?php
                            $current_year = current_time('Y');
                            for ($i = $current_year; $i >= $current_year - 5; $i--) {
                                echo '<option value="' . esc_attr($i) . '">' . esc_html($i) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="fsbhoa-summary-container" style="margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h3 style="margin-top:0;">Attendance Summary (People)</h3>
                    <style>
                            #attendance-summary-table td, 
                            #attendance-summary-table th {
                                padding: 4px 10px !important; /* Reduces vertical padding */
                                line-height: 1.2 !important;  /* Tightens text */
                                font-size: 13px;
                            }
                            /* Optional: limit width if you want it side-by-side with something else */
                            .fsbhoa-summary-container { max-width: 100%; } 
                    </style>
                    <table class="wp-list-table widefat fixed striped table-view-list" id="attendance-summary-table">
                        <thead>
                            <tr>
                                <th style="font-weight:bold;">Amenity</th>
                                <th style="font-weight:bold;" id="header-today">Today</th>
                                <th style="font-weight:bold;" id="header-yesterday">Yesterday</th>
                                <th style="font-weight:bold;" id="header-month">Month Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="4">Loading data...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="fsbhoa-charts-container">
                            <div class="chart-wrapper" id="amenity-usage-chart-container">
                                <h3>Usage by Amenity</h3>
                                <canvas id="amenity-usage-chart"></canvas>
                            </div>
                            <div class="chart-wrapper" id="peak-hours-chart-container">
                                <h3>Peak Usage Hours</h3>
                                <canvas id="peak-hours-chart"></canvas>
                            </div>
                            <div class="chart-wrapper" id="gate-usage-chart-container">
                                <h3>Usage by Gate</h3>
                                <canvas id="gate-usage-chart"></canvas>
                            </div>
                </div>
            </div>
        </div>
        <?php
    }
}


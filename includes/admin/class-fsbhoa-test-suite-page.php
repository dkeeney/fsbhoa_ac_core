<?php
// includes/admin/class-fsbhoa-test-suite-page.php

if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Test_Suite_Page {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_trigger_custom_event', array( $this, 'handle_trigger_custom_event_ajax' ) );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'fsbhoa_ac_main_menu',
            'System Diagnostics',
            'Diagnostics',
            'manage_options',
            'fsbhoa_diagnostics',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'fsbhoa-ac_page_fsbhoa_diagnostics') {
            return;
        }
        wp_enqueue_script(
            'fsbhoa-test-suite-js',
            FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-test-suite.js',
            array('jquery'),
            FSBHOA_AC_PLUGIN_VERSION,
            true
        );
        // Step ids and labels only; the callbacks run server-side.
        $steps = array_map( function ( $step ) {
            return [ 'id' => $step['id'], 'label' => $step['label'] ];
        }, Fsbhoa_Test_Suite_Actions::get_steps() );

        wp_localize_script('fsbhoa-test-suite-js', 'fsbhoa_test_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('fsbhoa_test_suite_nonce'),
            'steps'    => $steps,
        ]);
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>System Communications Test Suite</h1>

            <p>Trigger a simulated swipe event with specific parameters.<br>
            Be aware that repeated use of the same card will be blocked by rate limit.</p>
            <div>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="custom-card-number">Card Number</label></th>
                            <td><input type="text" id="custom-card-number" class="regular-text" value="17659798"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="custom-serial-number">Controller Serial Number</label></th>
                            <td><input type="text" id="custom-serial-number" class="regular-text" value="425043852"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="custom-door-number">Door Number</label></th>
                            <td>
                                <select id="custom-door-number" class="regular-text">
                                    <option value="1">Door 1</option>
                                    <option value="2">Door 2</option>
                                    <option value="3">Door 3</option>
                                    <option value="4">Door 4</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="submit">
                <button id="run-custom-test-btn" class="button button-primary">Run Custom Test</button>
            </p>
            </br>
            <p>Click the button below to run a series of tests to ensure all services are communicating correctly.</p>
            <p>
                <button id="run-test-suite" class="button button-primary">Run Full Test Suite</button>
            </p>
            <?php
            // Hook for hardware plugins (like UHPPOTE) to add diagnostic tools
            do_action( 'fsbhoa_admin_diagnostics_tools' );
            ?>
            <hr>
            <h2>Test Results:</h2>
            <div id="test-results" style="font-family: monospace; background: #f1f1f1; padding: 15px; border-radius: 4px; max-height: 500px; overflow-y: auto;">
                <p>Waiting to start...</p>
            </div>
        </div>
        <?php
    }

    public function handle_trigger_custom_event_ajax() {
        check_ajax_referer('fsbhoa_test_suite_nonce', 'nonce');

        $payload_json = stripslashes($_POST['payload']);
        $payload = json_decode($payload_json, true);

        if (!$payload) {
            wp_send_json_error('Invalid payload data.');
            return;
        }

        // Ask hardware plugins to process the custom diagnostic event
        $result = apply_filters('fsbhoa_trigger_custom_hardware_event', false, $payload, $payload_json);

        if ( is_wp_error($result) ) {
            wp_send_json_error($result->get_error_message());
        } elseif ($result !== false) {
            wp_send_json_success('Successfully received by PHP.');
        } else {
            wp_send_json_error('No hardware plugin handled the custom event.');
        }
        wp_die();
    }

}

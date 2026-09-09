<?php
if ( ! defined( 'WPINC' ) ) { die; }

require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-sync-functions.php';

class Fsbhoa_Ac_Settings_Page {

    private $parent_slug = 'fsbhoa_ac_main_menu';

    // Where to write the config files for the services
    private $event_service_config_path = '/var/lib/fsbhoa/event_service.json';
    private $monitor_service_config_path = '/var/lib/fsbhoa/monitor_service.json';
    private $monitor_settings_option_group = 'fsbhoa_monitor_options';

    public function __construct() {
        $this->ensure_system_users_exist();

        // Automatically set the default API token if it doesn't exist
        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'settings_api_init' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_fsbhoa_save_monitor_settings', array( $this, 'ajax_save_monitor_settings' ) );
        add_action( 'wp_ajax_fsbhoa_save_general_settings', array( $this, 'ajax_save_general_settings' ) );
        add_action( 'wp_ajax_fsbhoa_generate_api_key', array( $this, 'ajax_generate_api_key' ) );
        add_action('wp_ajax_fsbhoa_save_pool_alarm', [$this, 'ajax_save_pool_alarm']);
        add_action('wp_ajax_fsbhoa_trigger_pool_alarm', [$this, 'ajax_trigger_pool_alarm']);
        add_action( 'wp_ajax_fsbhoa_test_discord_alert', array( $this, 'ajax_test_discord_alert' ) );
    }

    public function add_plugin_admin_menu() {
        add_menu_page('FSBHOA General Settings', 'FSBHOA AC', 'manage_options', $this->parent_slug, array( $this, 'render_general_settings_page' ), 'dashicons-id-alt', 25);
        add_submenu_page($this->parent_slug, 'General Settings', 'General Settings', 'manage_options', $this->parent_slug, array( $this, 'render_general_settings_page'), 13 );
        add_submenu_page($this->parent_slug, 'Live Monitor Settings', 'Monitor Settings', 'manage_options', 'fsbhoa_monitor_settings', array( $this, 'render_monitor_settings_page'),14 );
        add_submenu_page($this->parent_slug, 'Amenities', 'Amenities', 'manage_options', 'fsbhoa-ac-amenities', [new Fsbhoa_Amenity_Admin_Page(), 'render_page'], 15);
        add_submenu_page(
            $this->parent_slug,
            'Pool Intrusion Alarm',
            'Pool Alarm',
            'manage_options',
            'fsbhoa-ac-pool-alarm',
            [$this, 'render_pool_alarm_page'],
            17
        );
        // Let secondary plugins hook their submenus right here dynamically
        do_action('fsbhoa_register_admin_submenus', $this->parent_slug);
    }

    /**
     * Master function to write ALL service configuration files.
     * This is the single source of truth for generating configs. It reads all
     * values directly from the database to ensure consistency.
     * It should be called after any relevant options have been updated.
     *
     * @return void
     */
    private function update_all_service_configs() {
        // --- Gather all settings from the database ---
        $monitor_port     = get_option('fsbhoa_ac_monitor_port', 8082);
        $websocket_port   = get_option('fsbhoa_ac_websocket_port', 8083);
        $tls_cert_path    = get_option('fsbhoa_ac_tls_cert_path', '/etc/letsencrypt/live/nas.fsbhoa.com/fullchain.pem');
        $tls_key_path     = get_option('fsbhoa_ac_tls_key_path', '/etc/letsencrypt/live/nas.fsbhoa.com/privkey.pem');
        $wp_host          = get_option('fsbhoa_ac_wp_host', 'access.fsbhoa.com');
        // Determine the correct protocol based on whether TLS is configured.
        $protocol         = (!empty($tls_cert_path) && !empty($tls_key_path)) ? 'https' : 'http';
        $wp_port          = get_option('fsbhoa_ac_wp_port', 443);
        $event_log_path   = get_option('fsbhoa_ac_event_log_path', '');
        $debug_mode       = get_option('fsbhoa_ac_debug_mode', 'on');
        $test_stub        = get_option('fsbhoa_ac_test_stub', 'on');
        $kiosk_port       = get_option('fsbhoa_kiosk_port', 8080);

        $pool_alarm_enabled     = get_option('fsbhoa_pool_alarm_enabled', '0');
        $pool_alarm_enable_url  = get_option('fsbhoa_pool_alarm_enable_url', '');
        $pool_alarm_disable_url = get_option('fsbhoa_pool_alarm_disable_url', '');
        $pool_alarm_gates       = get_option('fsbhoa_pool_alarm_gates', []);

        // --- Build and write monitor_service.json ---
        $monitor_config = [
            'listen_addr'       => ':' . absint($monitor_port),
            'wordpress_api'     => get_site_url() . '/wp-json/fsbhoa/v1/monitor/event',
            'tls_cert_path'     => sanitize_text_field($tls_cert_path),
            'tls_key_path'      => sanitize_text_field($tls_key_path),
            'event_service_url' => sprintf('%s://%s:%d', $protocol, $wp_host, absint($websocket_port)),
            'photo_event_limit' => (int) get_option('fsbhoa_ac_monitor_photo_limit', 3),
            'kiosk_service_url' => sprintf('%s://%s:%d', $protocol, $wp_host, absint($kiosk_port)),
            'api_key'           => get_option('fsbhoa_ac_kiosk_api_key', ''),
        ];
        $this->write_config_file($this->monitor_service_config_path, $monitor_config);

        
        do_action('fsbhoa_update_service_configs');
        // NOTE: Future config files can be added here.
    }
    
    // Helper function to write JSON config files
    private function write_config_file($path, $data) {
        $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $config_dir = dirname($path);
        if (!is_dir($config_dir)) {
            mkdir($config_dir, 0755, true);
        }
        file_put_contents($path, $json_data);
    }



    public function settings_api_init() {
        // --- Option Groups ---
        $general_option_group = 'fsbhoa_general_options';
        $event_service_option_group = 'fsbhoa_event_service_options';
        $monitor_settings_option_group = 'fsbhoa_monitor_options';

        // --- Page Slugs ---
        $general_page_slug = $this->parent_slug;
        $event_service_page_slug = 'fsbhoa_event_service_settings';

        // ====================================================================
        // --- GENERAL SETTINGS ---
        // ====================================================================
        // Section: Photo Editor
        add_settings_section('fsbhoa_ac_photo_editor_section', 'Photo Editor Settings', null, $general_page_slug);
        add_settings_field('fsbhoa_ac_photo_width_field', 'Photo Width (px)', array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_photo_editor_section', ['id' => 'fsbhoa_ac_photo_width', 'type' => 'number', 'default' => 640]);
        add_settings_field('fsbhoa_ac_photo_height_field', 'Photo Height (px)', array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_photo_editor_section', ['id' => 'fsbhoa_ac_photo_height', 'type' => 'number', 'default' => 800]);
        
        // Section: Display Options
        add_settings_section('fsbhoa_ac_display_options_section', 'Display Options', null, $general_page_slug);
        add_settings_field('fsbhoa_ac_address_suffix_field', 'Address Suffix to Remove', array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_display_options_section', ['id' => 'fsbhoa_ac_address_suffix', 'type' => 'text', 'default' => 'Bakersfield, CA 93306', 'desc' => 'This text will be removed from property addresses in display lists.']);

        // Service Communication 
        add_settings_section('fsbhoa_ac_service_comm_section', 'Service Communication Settings', null, $general_page_slug);
        $comm_fields = [
            'fsbhoa_ac_wp_host'         => ['label' => 'WordPress API Host', 'default' => 'nas.fsbhoa.com'],
            'fsbhoa_ac_wp_port'         => ['label' => 'WordPress API Port', 'type' => 'number', 'default' => 443],
            'fsbhoa_ac_tls_cert_path'   => ['label' => 'TLS Certificate Path', 'default' => '/etc/letsencrypt/live/nas.fsbhoa.com/fullchain.pem'],
            'fsbhoa_ac_tls_key_path'    => ['label' => 'TLS Key Path', 'default' => '/etc/letsencrypt/live/nas.fsbhoa.com/privkey.pem'],
        ];
        foreach ($comm_fields as $id => $field) {
            add_settings_field($id . '_field', $field['label'], array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_service_comm_section', ['id' => $id] + $field);
        }
        add_settings_section('fsbhoa_ac_api_keys_section', 'API Key Settings', null, $general_page_slug);
        add_settings_section('fsbhoa_ac_operational_section', 'Operational Settings', null, $general_page_slug);
        add_settings_field(
            'fsbhoa_ac_rate_limit_minutes_field',
            'Swipe Rate-Limit (Minutes)',
            array($this, 'render_field_callback'),
            $general_page_slug,
            'fsbhoa_ac_operational_section',
            [
                'id'      => 'fsbhoa_ac_rate_limit_minutes',
                'type'    => 'number',
                'default' => 10,
                'desc'    => 'Ignore duplicate swipes from the same person at the same location within this time. Set to 0 to disable.'
            ]
        );

        add_settings_field(
            'fsbhoa_ac_api_key_field',
            'CSV Import API Key',
            array($this, 'render_api_key_field'),
            $general_page_slug,
            'fsbhoa_ac_api_keys_section',
            ['id' => 'fsbhoa_ac_api_key', 'desc' => 'Secret key used to authorize automated CSV imports via the REST API.']
        );
        // Section: Amenity Tracking
        add_settings_section('fsbhoa_ac_amenity_tracking_section', 'Amenity Tracking Settings', null, $general_page_slug);
        add_settings_field(
            'fsbhoa_ac_amenity_clear_minutes_field',
            'Amenity Clearing Window (Minutes)',
            array($this, 'render_field_callback'),
            $general_page_slug,
            'fsbhoa_ac_amenity_tracking_section',
            [
                'id'      => 'fsbhoa_ac_amenity_clear_minutes',
                'type'    => 'number',
                'default' => 10,
                'desc'    => 'The time window (in minutes) to look back for a previous entry (Kiosk/Entry Gate) to be cleared by an INNER_GATE swipe. Set to 0 to disable clearing logic.'
            ]
        );
        add_settings_field(
            'fsbhoa_ac_default_court_amenity_name_field', 
            'After Hours Court Default Name',
            array($this, 'render_field_callback'), 
            $general_page_slug,
            'fsbhoa_ac_amenity_tracking_section',
            [
                'id'      => 'fsbhoa_ac_default_court_amenity_name', 
                'type'    => 'text',
                'default' => 'Courts', // Set a reasonable default name
                'desc'    => 'The descriptive name (e.g., "Courts") used to log amenity usage when a resident uses the After Hours Entry (West Gate) but not an inner amenity gate.'
            ]
        );
        // Section: Discord Alert & Notification Settings
        add_settings_section('fsbhoa_ac_alerts_section', 'Discord Alert & Notification Settings', null, $general_page_slug);

        add_settings_field(
            'fsbhoa_ac_discord_webhook_url_field',
            'Discord Webhook URL',
            array($this, 'render_discord_webhook_field'),
            $general_page_slug,
            'fsbhoa_ac_alerts_section',
            [
                'id'      => 'fsbhoa_ac_discord_webhook_url',
                'type'    => 'password', // Masks the token on screen
                'default' => '',
                'desc'    => 'The full Webhook URL from Discord. Leave blank to disable push notifications.'
            ]
        );

        add_settings_field(
            'fsbhoa_ac_discord_username_field',
            'Discord Bot Username',
            array($this, 'render_field_callback'),
            $general_page_slug,
            'fsbhoa_ac_alerts_section',
            [
                'id'      => 'fsbhoa_ac_discord_username',
                'type'    => 'text',
                'default' => 'Access Control Monitor',
                'desc'    => 'The name that will appear on the alert messages in Discord.'
            ]
        );
        
        // Register all General settings
        register_setting($general_option_group, 'fsbhoa_ac_photo_width', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_photo_height', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_address_suffix', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_wp_host', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_wp_port', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_tls_cert_path', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_tls_key_path', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_api_key', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_rate_limit_minutes', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_amenity_clear_minutes', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_default_court_amenity_name', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_discord_webhook_url', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_discord_username', 'sanitize_text_field');



        // ====================================================================
        // --- MONITOR SETTINGS ---
        // ====================================================================

        register_setting($this->monitor_settings_option_group, 'fsbhoa_monitor_map_url', 'esc_url_raw');
        register_setting($this->monitor_settings_option_group, 'fsbhoa_ac_monitor_port', 'absint');
        register_setting($this->monitor_settings_option_group, 'fsbhoa_ac_monitor_photo_limit', 'absint');
    }


    public function render_field_callback($args) {
        $id      = $args['id'];
        $type    = $args['type'] ?? 'text';
        $default = $args['default'] ?? '';
        $desc    = $args['desc'] ?? '';
        $value   = get_option($id, $default);

        if ($type === 'checkbox') {
            echo "<input type='checkbox' name='{$id}' value='on' " . checked($value, 'on', false) . " />";
        } else {
            echo "<input type='{$type}' name='{$id}' value='" . esc_attr($value) . "' class='regular-text' />";
        }
        if ($desc) {
            echo "<p class='description'>" . esc_html($desc) . "</p>";
        }
    }

    public function render_general_settings_page() {
        ?>
        <div class="wrap" id="fsbhoa-general-settings-page">
            <h1>General Plugin Settings</h1>
            <?php
                // We manually render the sections and fields without a <form> tag
                do_settings_sections($this->parent_slug);
            ?>
             <p class="submit">
                <button type="button" id="fsbhoa-save-general-settings-button" class="button button-primary">Save General Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
        </div>
        <?php
    }

    public function render_discord_webhook_field($args) {
        $id    = $args['id'];
        $value = get_option($id, '');
        $desc  = $args['desc'] ?? '';
        ?>
        <input type="password" name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <button type="button" class="button" id="btn-test-discord">Test Webhook</button>
        <span id="discord-test-feedback" style="margin-left: 10px; font-weight: bold;"></span>
        <p class="description">
            <?php echo esc_html($desc); ?><br>
            <em>Note: If you just pasted a new URL, click "Save General Settings" at the bottom of the page before testing.</em>
        </p>
        <script>
        jQuery(document).ready(function($) {
            $('#btn-test-discord').on('click', function(e) {
                e.preventDefault();
                var feedback = $('#discord-test-feedback');
                var btn = $(this);

                btn.prop('disabled', true);
                feedback.text('Sending...').css('color', '#000');

                $.post(ajaxurl, {
                    action: 'fsbhoa_test_discord_alert',
                    nonce: fsbhoa_settings_vars.general_nonce
                }, function(response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        feedback.text(response.data).css('color', 'green');
                    } else {
                        feedback.text('Error: ' + response.data).css('color', 'red');
                    }
                    setTimeout(function() { feedback.text(''); }, 5000);
                }).fail(function() {
                    btn.prop('disabled', false);
                    feedback.text('AJAX request failed.').css('color', 'red');
                    setTimeout(function() { feedback.text(''); }, 5000);
                });
            });
        });
        </script>
        <?php
    }



    public function render_monitor_settings_page() {
        ?>
        <div class="wrap">
            <style>
                #fsbhoa-gate-legend ol {
                    margin-top: 0;
                    margin-bottom: 0;
                }
                #fsbhoa-gate-legend li {
                    margin-bottom: 4px; /* Tighter spacing between items */
                    line-height: 1.2;   /* Tighter line height for multi-line text */
                    font-size: 13px;    /* Optional: slightly smaller text if list is long */
                }
            </style>
            <h1>Live Monitor Settings</h1>
            <p>Use these tools to configure the Live Monitor service and its map display.</p>
            <hr>
    
            <h2>Gate Position Editor</h2>
            <p class="description">Upload a map image, then drag the gate markers to their correct positions. All settings will be saved with the button at the bottom.</p>
            <div id="fsbhoa-editor-area" style="display: flex; gap: 20px; margin-top: 1em;">
                
                <div style="flex-basis: 70%;">
                    <div id="fsbhoa-map-editor-container" style="position: relative; border: 2px solid #ccc; display: inline-block;">
                        <img id="fsbhoa-map-editor-bg" src="<?php echo esc_url(get_option('fsbhoa_monitor_map_url', '')); ?>" style="max-width: 100%; display: block; opacity: 0.7;">
                    </div>
                </div>

                <div id="fsbhoa-gate-legend" style="flex-basis: 30%;">
                    <h3 style="margin-top: 0;">Gate Legend</h3>
                    <p class="description">Drag dots on map to set position.</p>
                    <ol style="margin-left: 20px; background: #fff; border: 1px solid #ddd; padding: 10px;"></ol>
                </div>
            </div>
            <p style="margin-top: 15px;">
                <button type="button" class="button" id="fsbhoa_monitor_map_url-button">Upload/Change Map</button>
            </p>
    
            <hr style="margin: 2em 0;">
    
            <h2>Monitor Service Settings</h2>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="fsbhoa_ac_monitor_port">Monitor Service Port (WSS)</label>
                        </th>
                        <td>
                            <input name="fsbhoa_ac_monitor_port" type="number" id="fsbhoa_ac_monitor_port" value="<?php echo esc_attr(get_option('fsbhoa_ac_monitor_port', 8082)); ?>" class="regular-text" />
                            <p class="description">The port the monitor_service listens on for secure WebSocket (WSS) connections.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fsbhoa_monitor_status_group_id">Status Indicator Group</label>
                        </th>
                        <td>
                            <?php 
                            global $wpdb;
                            $groups = $wpdb->get_results("SELECT group_id, group_name FROM ac_groups ORDER BY group_name ASC");
                            $selected_group = get_option('fsbhoa_monitor_status_group_id', 0);
                            ?>
                            <select name="fsbhoa_monitor_status_group_id" id="fsbhoa_monitor_status_group_id">
                                <option value="0">-- None (Always Solid) --</option>
                                <?php foreach ($groups as $g) : ?>
                                    <option value="<?php echo esc_attr($g->group_id); ?>" <?php selected($selected_group, $g->group_id); ?>>
                                        <?php echo esc_html($g->group_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select the group (e.g., "Residents") whose schedule will determine if the yellow status dots throb (access allowed) or stay solid (access denied).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fsbhoa_ac_monitor_photo_limit">Photo Event Limit</label>
                        </th>
                        <td>
                            <input name="fsbhoa_ac_monitor_photo_limit" type="number" id="fsbhoa_ac_monitor_photo_limit" value="<?php echo esc_attr(get_option('fsbhoa_ac_monitor_photo_limit', 3)); ?>" class="small-text" />
                            <p class="description">The number of recent events to display with a photo in the activity log.</p>
                        </td>
                    </tr>
                </tbody>
            </table>
    
            <input type="hidden" id="fsbhoa_monitor_map_url" name="fsbhoa_monitor_map_url" value="<?php echo esc_attr(get_option('fsbhoa_monitor_map_url', '')); ?>" />

            <p class="submit">
                <button type="button" id="fsbhoa-save-monitor-settings-button" class="button button-primary">Save All Monitor Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
    
        </div>
        <?php
    }



    public function enqueue_admin_assets($hook) {
        // For General, Event, & Print Settings Pages
        $settings_pages = [
            'toplevel_page_fsbhoa_ac_main_menu',
            'fsbhoa-ac_page_fsbhoa_event_service_settings',
            'fsbhoa-ac_page_fsbhoa-ac-pool-alarm'
        ];

        if (in_array($hook, $settings_pages)) {
            wp_enqueue_media(); // Needed for the image uploader
            $script_handle = 'fsbhoa-settings-script';
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-settings-admin.js', array('jquery'), FSBHOA_AC_VERSION, true);

            wp_localize_script(
                $script_handle,
                'fsbhoa_settings_vars',
                array(
                    'ajax_url'      => admin_url('admin-ajax.php'),
                    'general_nonce' => wp_create_nonce('fsbhoa_general_settings_nonce'),
                    'event_nonce'   => wp_create_nonce('fsbhoa_event_settings_nonce'),
                    'pool_alarm_nonce' => wp_create_nonce('fsbhoa_pool_alarm_nonce'),
                    'generate_api_key_nonce' => wp_create_nonce('fsbhoa_generate_api_key_nonce'),
                )
            );
        }

        // For Monitor Settings Page
        if ($hook === 'fsbhoa-ac_page_fsbhoa_monitor_settings') {
            wp_enqueue_media();
            wp_enqueue_style('fsbhoa-monitor-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-monitor.css', array(), FSBHOA_AC_VERSION);

            $script_handle = 'fsbhoa-monitor-settings-script';
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-monitor-settings.js', array('jquery'), FSBHOA_AC_VERSION, true);

            wp_localize_script(
                $script_handle,
                'fsbhoa_monitor_settings_vars',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('fsbhoa_monitor_settings_nonce'),
                )
            );
        }

    }


    public function ajax_save_monitor_settings() {
        // 1. Security Checks
        check_ajax_referer('fsbhoa_monitor_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        // 2. Process Incoming Data
        $gates_data = isset($_POST['gates']) && is_array($_POST['gates']) ? $_POST['gates'] : [];
        $map_url = isset($_POST['map_url']) ? esc_url_raw($_POST['map_url']) : '';
        $port = isset($_POST['port']) ? absint($_POST['port']) : 8082;
        $status_group = isset($_POST['status_group_id']) ? absint($_POST['status_group_id']) : 0;
        $photo_limit = isset($_POST['photo_limit']) ? absint($_POST['photo_limit']) : 3;
        $errors = [];
        
        // 3. Save Gate Positions to the Database with detailed error checking
        global $wpdb;
        $doors_table = 'ac_doors';
        foreach ($gates_data as $gate) {
            $door_id = absint($gate['id']);
            $map_x = intval($gate['x']); // Stored as integer percentages
            $map_y = intval($gate['y']);

            if ($door_id > 0) {
                $result = $wpdb->update(
                    $doors_table,
                    ['map_x' => $map_x, 'map_y' => $map_y],
                    ['door_record_id' => $door_id],
                    ['%d', '%d'],
                    ['%d']
                );
                
                // If the update fails, capture the specific DB error
                if ($result === false) {
                    $errors[] = "Error updating Gate ID {$door_id}: " . $wpdb->last_error;
                }
            }
        }
        
        // 4. Save Other Settings to wp_options
        update_option('fsbhoa_monitor_map_url', $map_url);
        update_option('fsbhoa_ac_monitor_port', $port);
        update_option('fsbhoa_monitor_status_group_id', $status_group);
        update_option('fsbhoa_ac_monitor_photo_limit', $photo_limit);

        // Trigger Cache Rebuild Immediately so the monitor updates instantly
        if (function_exists('fsbhoa_rebuild_monitor_status_cache')) {
            fsbhoa_rebuild_monitor_status_cache();
        }
        
        // 5. Trigger the Master Config Writer
        // This runs regardless of gate update errors, so the config files are always
        // written based on the options that were successfully saved.
        $this->update_all_service_configs();
        
        // 6. Send Response
        if (empty($errors)) {
            wp_send_json_success('Monitor settings saved and config files updated.');
        } else {
            // Send back the specific database errors
            $error_message = implode("\n", $errors);
            wp_send_json_error($error_message);
        }
    }

    public function ajax_save_general_settings() {
        check_ajax_referer('fsbhoa_general_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $options = isset($_POST['options']) ? $_POST['options'] : [];
        if (!empty($options)) {
            foreach ($options as $option) {
                // All general options are text fields for now, so we can sanitize them the same way.
                // We can add more specific sanitization here if needed in the future.
                update_option(sanitize_key($option['name']), sanitize_text_field($option['value']));
            }
        }

        $this->update_all_service_configs();
        wp_send_json_success('General settings saved.');
    }



    /**
     * Renders the special read-only field for the API key with a generate button.
     */
    public function render_api_key_field($args) {
        $id    = $args['id'];
        $desc  = $args['desc'] ?? '';
        $value = get_option($id, '');
        ?>
        <input type="text" name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" readonly="readonly" placeholder="Click generate to create a new key" />
        <button type="button" class="button" id="fsbhoa-generate-<?php echo esc_attr( str_replace('fsbhoa_', '', $id) ); ?>-button">Generate New Key</button>
        <p class="description"><?php echo esc_html($desc); ?></p>
        <?php
    }

    /**
     * AJAX handler to generate a new, cryptographically secure API key.
     */
    public function ajax_generate_api_key() {
        check_ajax_referer('fsbhoa_generate_api_key_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        // Generate a new 32-byte (256-bit) random key and encode it
        $new_key = base64_encode(random_bytes(32));

        wp_send_json_success(['api_key' => $new_key]);
    }

    public function render_media_uploader_field($args) {
        $id    = $args['id'];
        $value = get_option($id, '');
        $desc  = $args['desc'] ?? '';
        ?>
        <input type="text" name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <button type="button" class="button" id="<?php echo esc_attr($id); ?>-button">Upload/Select Image</button>
        <p class="description"><?php echo esc_html($desc); ?></p>
        <?php
    }

    /**
     * Checks if system-critical properties and users exist and creates them if not.
     * This runs when the settings page is loaded.
     */
    private function ensure_system_users_exist() {
        global $wpdb;
        $cardholders_table = 'ac_cardholders';
        $properties_table = 'ac_property';

        // --- Step 1: Find or Create the "System" Property ---
        $system_property_id = $wpdb->get_var($wpdb->prepare("SELECT property_id FROM {$properties_table} WHERE street_address = %s", 'System'));

        if (!$system_property_id) {
            $wpdb->insert(
                $properties_table,
                [
                    'house_number'   => '0',
                    'street_name'    => 'System',
                    'street_address' => 'System',
                    'origin'         => 'system'
                ]
            );
            $system_property_id = $wpdb->insert_id;
        }

        // --- Step 2: Define System Users ---
        $system_users = [
            [
                'rfid_id'    => '11111111',
                'first_name' => 'System',
                'last_name'  => 'Test User'
            ],
            [
                'rfid_id'    => '22222222',
                'first_name' => 'Kiosk',
                'last_name'  => 'Test User'
            ]
        ];

        // --- Step 3: Loop Through and Create Missing Users ---
        foreach ($system_users as $user_data) {
            // 1. Check the new credentials table instead of the monolith
            $user_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ac_credentials WHERE credential_value = %s", $user_data['rfid_id']));

            if (!$user_exists) {
                // 2. Extract the RFID before inserting the Human
                $rfid_to_insert = $user_data['rfid_id'];
                unset($user_data['rfid_id']);

                // Ensure we use the new column name for the Human
                $user_data['cardholder_status'] = 'active';
                $user_data['origin'] = 'system';
                $user_data['property_id'] = $system_property_id;

                // 3. Insert the Human
                $wpdb->insert($cardholders_table, $user_data);
                $new_cardholder_id = $wpdb->insert_id;

                // 4. Insert the Credential
                if ($new_cardholder_id) {
                    $wpdb->insert('ac_credentials', [
                        'cardholder_id'    => $new_cardholder_id,
                        'credential_type'  => 'MIFARE_BADGE',
                        'credential_value' => $rfid_to_insert,
                        'status'           => 'active'
                    ]);
                }
            }
        }
    }

    public function render_pool_alarm_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Pool Intrusion Alarm Settings</h1>

            <!-- Manual Trigger Buttons Section -->
            <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h2>Manual Controls</h2>
                <p>Use these buttons to manually test or trigger the pool intrusion alarm endpoints without physically swiping a gate.</p>
                <button type="button" id="btn-enable-pool-alarm" class="button button-primary" style="margin-right: 10px;">Test Enable Alarm</button>
                <button type="button" id="btn-disable-pool-alarm" class="button">Test Disable Alarm</button>
                <span id="pool-alarm-action-feedback" style="margin-left: 10px; font-weight: bold;"></span>
            </div>

            <div id="tab-pool-alarm">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="pool_alarm_enabled">Integration Enabled</label></th>
                        <td>
                            <input type="checkbox" id="pool_alarm_enabled" name="pool_alarm_enabled" value="1" <?php checked(get_option('fsbhoa_pool_alarm_enabled', '0'), '1'); ?> />
                            <p class="description">Check to enable automatic triggers via the Event Service.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pool_alarm_enable_url">Enable URL</label></th>
                        <td>
                            <?php $enable_url = get_option('fsbhoa_pool_alarm_enable_url', ''); ?>
                            <input type="url" id="pool_alarm_enable_url" name="pool_alarm_enable_url" value="<?php echo esc_attr($enable_url); ?>" class="regular-text" placeholder="http://testbed.fsbhoa.com:8090/resume">
                            <p class="description">The URL that will be triggered to ENABLE the pool alarm.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pool_alarm_disable_url">Disable URL</label></th>
                        <td>
                            <?php $disable_url = get_option('fsbhoa_pool_alarm_disable_url', ''); ?>
                            <input type="url" id="pool_alarm_disable_url" name="pool_alarm_disable_url" value="<?php echo esc_attr($disable_url); ?>" class="regular-text" placeholder="https://testbed.fsbhoa.com:8090/override">
                            <p class="description">The URL that will be triggered to DISABLE the pool alarm.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pool_alarm_trigger_gates">Trigger Gates</label></th>
                        <td>
                            <?php
                            global $wpdb;
                            // Dropped the WP prefix and updated the primary key column
                            $gates = $wpdb->get_results("SELECT door_record_id, friendly_name FROM ac_doors ORDER BY friendly_name ASC");
                            $saved_gates = get_option('fsbhoa_pool_alarm_gates', []);
                            if (!is_array($saved_gates)) $saved_gates = [];
                            ?>
                            <select id="pool_alarm_trigger_gates" name="pool_alarm_trigger_gates[]" multiple="multiple" style="min-width: 300px; height: 150px;">
                                <?php if ($gates): ?>
                                    <?php foreach ($gates as $gate) : ?>
                                        <option value="<?php echo esc_attr($gate->door_record_id); ?>" <?php echo in_array($gate->door_record_id, $saved_gates) ? 'selected' : ''; ?>>
                                            <?php echo esc_html($gate->friendly_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No gates found in database</option>
                                <?php endif; ?>
                            </select>
                            <p class="description">Select the gates that will trigger the disable URL. Hold CTRL (or CMD) to select multiple.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="button" id="fsbhoa-save-pool-alarm-settings-button" class="button button-primary">Save Pool Alarm Settings</button>
                    <span id="fsbhoa-save-feedback" style="display:none; margin-left:10px;"></span>
                </p>
            </div>
        </div>

        <!-- Inline script to handle the newly added Action Buttons instantly -->
        <script>
        jQuery(document).ready(function($) {
            $('#btn-enable-pool-alarm, #btn-disable-pool-alarm').on('click', function() {
                var action = $(this).attr('id') === 'btn-enable-pool-alarm' ? 'enable' : 'disable';
                var feedback = $('#pool-alarm-action-feedback');
                var btn = $(this);

                btn.prop('disabled', true);
                feedback.text('Sending request...').css('color', '#000');

                $.post(ajaxurl, {
                    action: 'fsbhoa_trigger_pool_alarm',
                    nonce: '<?php echo wp_create_nonce("fsbhoa_pool_alarm_nonce"); ?>',
                    alarm_action: action
                }, function(response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        feedback.text(response.data).css('color', 'green');
                    } else {
                        feedback.text('Error: ' + response.data).css('color', 'red');
                    }
                    setTimeout(function() { feedback.text(''); }, 5000);
                }).fail(function() {
                    btn.prop('disabled', false);
                    feedback.text('AJAX request failed.').css('color', 'red');
                    setTimeout(function() { feedback.text(''); }, 5000);
                });
            });
        });
        </script>
        <?php
    }
    public function ajax_save_pool_alarm() {
        // Verify against the newly matched nonce hook
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fsbhoa_pool_alarm_nonce')) {
            wp_send_json_error('Permission denied.');
        }

        $options = isset($_POST['options']) ? $_POST['options'] : [];
        $trigger_gates = [];
        $disable_url = '';
        $enable_url = '';
        $enabled = '0';

        foreach ($options as $opt) {
            if ($opt['name'] === 'pool_alarm_disable_url') {
                $disable_url = esc_url_raw($opt['value']);
            } elseif ($opt['name'] === 'pool_alarm_enable_url') {
                $enable_url = esc_url_raw($opt['value']);
            } elseif ($opt['name'] === 'pool_alarm_enabled') {
                // FIX 1: Actually check the payload value
                $enabled = ($opt['value'] === 'on') ? '1' : '0';
            } elseif ($opt['name'] === 'pool_alarm_trigger_gates[]') {
                // FIX 2: Handle the array correctly
                if (is_array($opt['value'])) {
                    foreach ($opt['value'] as $gate_id) {
                        $trigger_gates[] = intval($gate_id);
                    }
                } else {
                    // Fallback in case only a single string gets passed somehow
                    $trigger_gates[] = intval($opt['value']);
                }
            }
        }

        update_option('fsbhoa_pool_alarm_enabled', $enabled);
        update_option('fsbhoa_pool_alarm_enable_url', $enable_url);
        update_option('fsbhoa_pool_alarm_disable_url', $disable_url);
        update_option('fsbhoa_pool_alarm_gates', array_unique($trigger_gates));

        // Rebuild the JSON files
        $this->update_all_service_configs();

        wp_send_json_success('Pool Alarm settings saved.');
    }

    public function ajax_trigger_pool_alarm() {
        check_ajax_referer('fsbhoa_pool_alarm_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $action = isset($_POST['alarm_action']) ? sanitize_text_field($_POST['alarm_action']) : '';
        $url = '';

        if ($action === 'enable') {
            $url = get_option('fsbhoa_pool_alarm_enable_url', '');
        } elseif ($action === 'disable') {
            $url = get_option('fsbhoa_pool_alarm_disable_url', '');
        }

        if (empty($url)) {
            wp_send_json_error('The ' . esc_html($action) . ' URL is not configured.');
        }

        // Fire off the HTTP request
        $response = wp_remote_get($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to trigger URL: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 200 && $status_code < 300) {
            wp_send_json_success('Successfully triggered ' . esc_html($action) . ' command (Status: ' . $status_code . ')');
        } else {
            wp_send_json_error('Received HTTP ' . $status_code . ' from alarm system.');
        }
    }

    public function ajax_test_discord_alert() {
        check_ajax_referer('fsbhoa_general_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        if ( function_exists('fsbhoa_send_discord_alert') ) {
            // Note: We recommend users click 'Save General Settings' first if they just pasted a new URL
            fsbhoa_send_discord_alert("✅ **Test Successful:** The FSBHOA Access Control webhook is communicating perfectly!");
            wp_send_json_success('Test alert sent to Discord.');
        } else {
            wp_send_json_error('Alert function not found.');
        }
    }
}

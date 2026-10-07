<?php
/**
 * Handles actions for Kiosk Amenities.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Amenity_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_add_amenity', array($this, 'handle_add_or_edit_amenity'));
        add_action('admin_post_fsbhoa_edit_amenity', array($this, 'handle_add_or_edit_amenity'));
        add_action('admin_post_fsbhoa_delete_amenity', array($this, 'handle_delete_action')); 
        add_action('admin_post_fsbhoa_move_amenity_up', array($this, 'handle_move_up'));
        add_action('admin_post_fsbhoa_move_amenity_down', array($this, 'handle_move_down'));
        add_action('admin_post_fsbhoa_toggle_amenity_status', array($this, 'handle_toggle_status'));
        add_action('wp_ajax_fsbhoa_edit_amenity', array($this, 'handle_ajax_edit_amenity'));
    }

    /**
     * Tells extension plugins that the amenity list changed (added, edited, deleted,
     * reordered, or turned on/off), e.g. so a kiosk can refresh its buttons.
     */
    private function amenities_changed() {
        do_action('fsbhoa_core_amenities_changed');
    }

    public function handle_add_or_edit_amenity() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'fsbhoa_amenity_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed.');
        }

        global $wpdb;
        $table_name = 'ac_amenities';
        $amenity_id = isset($_POST['amenity_id']) ? absint($_POST['amenity_id']) : 0;
        $redirect_url = $_POST['_wp_http_referer'] ?? admin_url('admin.php?page=fsbhoa-ac-amenities');

        $data = [
            'name'      => sanitize_text_field($_POST['name']),
            'image_url' => isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : null,
        ];
        if (empty($data['name'])) { wp_die('Amenity name cannot be empty.'); }

        if ($amenity_id > 0) {
            $result = $wpdb->update($table_name, $data, ['id' => $amenity_id]);
            $redirect_url .= '#amenity-' . $amenity_id; // Add anchor for edits
        } else {
            $max_order = $wpdb->get_var("SELECT MAX(display_order) FROM {$table_name}");
            if ($wpdb->last_error) { wp_die('Database error: ' . esc_html($wpdb->last_error)); }
            $data['display_order'] = is_null($max_order) ? 10 : $max_order + 10;
            $data['is_active'] = 1; // Default to active on creation
            $result = $wpdb->insert($table_name, $data);
            $redirect_url .= '#add-amenity-form'; // Add anchor for new adds
        }

        if ($result === false) {
            $error_message = 'Database Error: Could not add the amenity.';
            error_log('FSBHOA DB Error on amenity save: ' . $wpdb->last_error);
            $redirect_url = add_query_arg('message', 'error', $redirect_url);
        } else {
            $this->amenities_changed();
            $redirect_url = add_query_arg('message', $message, $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_toggle_status() {
        $amenity_id = isset($_GET['amenity_id']) ? absint($_GET['amenity_id']) : 0;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fsbhoa_toggle_amenity_nonce_' . $amenity_id) || !current_user_can('manage_options')) {
            wp_die('Security check failed.');
        }

        global $wpdb;
        $table_name = 'ac_amenities';
        // Get current status and flip it
        $current_status = $wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$table_name} WHERE id = %d", $amenity_id));
        if (!is_null($current_status)) {
            $new_status = (intval($current_status) === 1) ? 0 : 1;
            if ($wpdb->update($table_name, ['is_active' => $new_status], ['id' => $amenity_id]) !== false) {
                $this->amenities_changed();
            }
        }

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    private function move_amenity($amenity_id, $direction) {
        global $wpdb;
        $table_name = 'ac_amenities';
        
        $current_item = $wpdb->get_row($wpdb->prepare("SELECT id, display_order FROM {$table_name} WHERE id = %d", $amenity_id));
        if ($wpdb->last_error) { wp_die('DB error getting current item: ' . esc_html($wpdb->last_error)); }
        if (!$current_item) return;

        $operator = ($direction === 'up') ? '<' : '>';
        $order_dir = ($direction === 'up') ? 'DESC' : 'ASC';
        $swap_item = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_order FROM {$table_name} WHERE display_order {$operator} %d ORDER BY display_order {$order_dir} LIMIT 1",
            $current_item->display_order
        ));
        if ($wpdb->last_error) { wp_die('DB error getting swap item: ' . esc_html($wpdb->last_error)); }

        if ($swap_item) {
            $result1 = $wpdb->update($table_name, ['display_order' => $swap_item->display_order], ['id' => $current_item->id]);
            $result2 = $wpdb->update($table_name, ['display_order' => $current_item->display_order], ['id' => $swap_item->id]);
            if ($result1 === false || $result2 === false) {
                wp_die('DB error swapping items: ' . esc_html($wpdb->last_error));
            }
            $this->amenities_changed();
        }

        $redirect_url = wp_get_referer();
        if ($redirect_url) {
            // Add the anchor hash to the URL
            $redirect_url .= '#amenity-' . $amenity_id;
        }
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_move_up() {
        $amenity_id = isset($_GET['amenity_id']) ? absint($_GET['amenity_id']) : 0;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fsbhoa_move_amenity_up_nonce_' . $amenity_id) || !current_user_can('manage_options')) {
            wp_die('Security check failed.');
        }
        $this->move_amenity($amenity_id, 'up');
    }

    public function handle_move_down() {
        $amenity_id = isset($_GET['amenity_id']) ? absint($_GET['amenity_id']) : 0;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fsbhoa_move_amenity_down_nonce_' . $amenity_id) || !current_user_can('manage_options')) {
            wp_die('Security check failed.');
        }
        $this->move_amenity($amenity_id, 'down');
    }

    
    public function handle_delete_action() {
        $amenity_id = isset($_GET['amenity_id']) ? absint($_GET['amenity_id']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';

        if (!$amenity_id || !wp_verify_nonce($nonce, 'fsbhoa_delete_amenity_nonce_' . $amenity_id) || !current_user_can('manage_options')) {
            wp_die('Security check failed.');
        }

        global $wpdb;
        $table_name = 'ac_amenities';
        $result = $wpdb->delete($table_name, ['id' => $amenity_id], ['%d']);

        $redirect_url = wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=fsbhoa-ac-amenities');
        $redirect_url = remove_query_arg(['action', 'amenity_id', '_wpnonce'], $redirect_url);

        if ($result === false) {
            $error_message = 'Database Error: Could not delete the amenity.';
            $redirect_url = add_query_arg('message', 'error', $redirect_url);
        } else {
            $this->amenities_changed();
            $redirect_url = add_query_arg('message', 'deleted', $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_ajax_edit_amenity() {
        // Security checks
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fsbhoa_amenity_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }

        $amenity_id = isset($_POST['amenity_id']) ? absint($_POST['amenity_id']) : 0;
        if (empty($amenity_id)) {
            wp_send_json_error(['message' => 'Invalid Amenity ID.']);
        }

        $data = [
            'name'      => isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
            'image_url' => isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '',
        ];

        if (empty($data['name'])) {
            wp_send_json_error(['message' => 'Amenity name cannot be empty.']);
        }

        global $wpdb;
        $table_name = 'ac_amenities';
        $result = $wpdb->update($table_name, $data, ['id' => $amenity_id]);

        if ($result === false) {
            wp_send_json_error(['message' => 'Database update failed: ' . $wpdb->last_error]);
        }
        $this->amenities_changed();

        // Send back the clean data to update the UI
        wp_send_json_success([
            'amenity' => [
                'id' => $amenity_id,
                'name' => $data['name'],
                'image_url' => $data['image_url']
            ]
        ]);
    }

}


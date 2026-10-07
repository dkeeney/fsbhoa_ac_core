<?php
/**
 * Creates the admin page for managing Kiosk Amenities.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Amenity_Admin_Page {
    public function __construct() {
        // Hook into WordPress to load our CSS and JS when this page loads
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function enqueue_assets($hook) {
        // Only load these assets on the Amenity page
        if (isset($_GET['page']) && $_GET['page'] === 'fsbhoa-ac-amenities') {

            // Enable the WordPress Media Uploader for the image fields
            wp_enqueue_media();

            // Load the CSS you just found
            wp_enqueue_style('fsbhoa-amenity-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-amenity-styles.css', array(), FSBHOA_AC_VERSION);

            // Load the JS for the edit buttons and AJAX saving
            wp_enqueue_script('fsbhoa-amenity-script', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-amenity-admin.js', array('jquery'), FSBHOA_AC_VERSION, true);

            // Pass the AJAX URL and security nonce to the Javascript file
            wp_localize_script('fsbhoa-amenity-script', 'fsbhoa_amenity_data', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('fsbhoa_amenity_nonce')
            ));
        }
    }

    public function render_page() {
        // Check for our custom error message in the URL
        if (isset($_GET['fsbhoa_error'])) {
            $error_message = sanitize_text_field(urldecode($_GET['fsbhoa_error']));
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_message) . '</p></div>';
        }
        $this->render_list_page();
    }

    private function render_list_page() {
        global $wpdb;
        $table_name = 'ac_amenities';
        $amenities = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY display_order ASC, name ASC");

        if ($wpdb->last_error) {
            echo '<div class="notice notice-error"><p>Database Error: ' . esc_html( $wpdb->last_error ) . '</p></div>';
        }
        ?>
        <div class="wrap fsbhoa-frontend-wrap fsbhoa-amenity-page">
            <h1 class="wp-heading-inline">Current Amenities</h1>
            <hr class="wp-header-end">
            <table id="amenities-list-table" class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th class="amenity-col-image">Image</th>
                        <th class="amenity-col-name">Name</th>
                        <th class="amenity-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="amenity-list-body">
                    <?php if ( ! empty($amenities) ) : foreach ( $amenities as $amenity ) : ?>
                        <tr id="amenity-<?php echo esc_attr($amenity->id); ?>" data-amenity-id="<?php echo esc_attr($amenity->id); ?>">
                            
                            <td class="amenity-image-cell">
                                <div class="amenity-image-display">
                                    <?php if (!empty($amenity->image_url)) : ?>
                                        <img class="amenity-list-image" src="<?php echo esc_url($amenity->image_url); ?>">
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" class="amenity-image-url-input" value="<?php echo esc_attr($amenity->image_url); ?>">
                            </td>
                            
                            <td>
                                <span class="amenity-name-display"><?php echo esc_html($amenity->name); ?></span>
                                <input type="text" class="amenity-name-input" value="<?php echo esc_attr($amenity->name); ?>" style="display: none; width: 100%;">
                            </td>

                            <td class="fsbhoa-actions-column">
                                <div class="amenity-actions-display">
                                    <?php
                                    $page_url = admin_url('admin.php?page=fsbhoa-ac-amenities');
                                    $up_nonce = wp_create_nonce('fsbhoa_move_amenity_up_nonce_' . $amenity->id);
                                    $down_nonce = wp_create_nonce('fsbhoa_move_amenity_down_nonce_' . $amenity->id);
                                    $up_link = esc_url(admin_url('admin-post.php?action=fsbhoa_move_amenity_up&amenity_id=' . $amenity->id . '&_wpnonce=' . $up_nonce));
                                    $down_link = esc_url(admin_url('admin-post.php?action=fsbhoa_move_amenity_down&amenity_id=' . $amenity->id . '&_wpnonce=' . $down_nonce));
                                    $is_active = (bool) $amenity->is_active;
                                    $toggle_nonce = wp_create_nonce('fsbhoa_toggle_amenity_nonce_' . $amenity->id);
                                    $toggle_url = esc_url(admin_url('admin-post.php?action=fsbhoa_toggle_amenity_status&amenity_id=' . $amenity->id . '&_wpnonce=' . $toggle_nonce));
                                    $toggle_class = $is_active ? 'is-enabled' : 'is-disabled';
                                    $toggle_title = $is_active ? 'Active. Click to deactivate.' : 'Inactive. Click to activate.';
                                    $toggle_icon = $is_active ? 'dashicons-yes-alt' : 'dashicons-no-alt';
                                    $delete_nonce = wp_create_nonce('fsbhoa_delete_amenity_nonce_' . $amenity->id);
                                    $delete_link = esc_url(admin_url('admin-post.php?action=fsbhoa_delete_amenity&amenity_id=' . $amenity->id . '&_wpnonce=' . $delete_nonce));
                                    ?>
                                    <a href="<?php echo $up_link; ?>" class="fsbhoa-action-icon" title="Move Up"><span class="dashicons dashicons-arrow-up-alt"></span></a>
                                    <a href="<?php echo $down_link; ?>" class="fsbhoa-action-icon" title="Move Down"><span class="dashicons dashicons-arrow-down-alt"></span></a>
                                    <a href="#" class="fsbhoa-action-icon edit-amenity-btn" title="Edit"><span class="dashicons dashicons-edit"></span></a>
                                    <a href="<?php echo $toggle_url; ?>" class="fsbhoa-action-icon amenity-status-toggle <?php echo $toggle_class; ?>" title="<?php echo esc_attr($toggle_title); ?>">
                                        <span class="dashicons <?php echo $toggle_icon; ?>"></span>
                                    </a>
                                    <a href="<?php echo $delete_link; ?>" title="Delete" class="fsbhoa-action-icon"><span class="dashicons dashicons-trash"></span></a>
                                </div>
                                <div class="amenity-actions-edit" style="display: none;">
                                    <button type="button" class="button button-primary save-amenity-btn">Save</button>
                                    <button type="button" class="button button-secondary cancel-edit-btn">Cancel</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="3"><?php esc_html_e( 'No amenities found.', 'fsbhoa-ac' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr id="add-amenity-form">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <td class="fsbhoa-actions-column">
                                <span class="dashicons dashicons-plus-alt"></span>
                            </td>
                            <td>
                                <input type="text" name="name" placeholder="Enter New Amenity Name" required>
                            </td>
                            <td>
                                <input type="hidden" name="action" value="fsbhoa_add_amenity">
                                <?php wp_nonce_field('fsbhoa_amenity_nonce'); ?>
                                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(remove_query_arg(['message'])); ?>">
                                <button type="submit" class="button button-primary">Add New Amenity</button>
                            </td>
                        </form>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }

    private function display_admin_notice($message_code) {
        $message = '';
        $class = 'notice-success';
        switch ($message_code) {
            case 'added': $message = 'Amenity added successfully.'; break;
            case 'updated': $message = 'Amenity updated successfully.'; break;
            case 'deleted': $message = 'Amenity deleted successfully.'; break;
            case 'error':
                $message = 'An error occurred. Please try again.';
                $class = 'notice-error';
                break;
        }
        if ($message) {
            echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }
}

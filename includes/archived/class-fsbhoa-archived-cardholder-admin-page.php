<?php
/**
 * Handles the display, preview, and restore actions for the Archived Cardholders page.
 * REFACTORED FOR SOFT-DELETE ARCHITECTURE.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Archived_Cardholder_Admin_Page {

    /**
     * Main render method. Acts as a controller to show the correct view.
     */
    public function render_page() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $message_code = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';

        ?>
        <div class="fsbhoa-frontend-wrap">
            <h1><?php esc_html_e( 'Archived Cardholders', 'fsbhoa-ac' ); ?></h1>

            <?php // The "Back" button now only shows on sub-pages
            if ( $action === 'preview_archived' || $action === 'merge_cardholder' ) : ?>
                <a href="<?php echo esc_url( remove_query_arg(['action', 'cardholder_id', 'source_id']) ); ?>" class="button">&larr; Back to Archive List</a>
            <?php endif; ?>

            <hr style="margin-top: 1em; margin-bottom: 1em;">

            <?php
            // Display feedback messages from redirects
            if ( $message_code === 'cardholder_restored' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cardholder restored successfully.', 'fsbhoa-ac' ) . '</p></div>';
            } elseif ( $message_code === 'bulk_restored' ) {
                $count = isset($_GET['processed_count']) ? absint($_GET['processed_count']) : 0;
                printf( '<div class="notice notice-success is-dismissible"><p>' . esc_html__('%d cardholder(s) restored successfully.', 'fsbhoa-ac') . '</p></div>', $count );
            } elseif ( $message_code === 'merge_success' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cardholder merged successfully. The source record has been purged.', 'fsbhoa-ac' ) . '</p></div>';
            } elseif ( $message_code === 'cardholder_purged' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cardholder purged. The record is now hidden but retained for historical reporting.', 'fsbhoa-ac' ) . '</p></div>';
            } elseif ( $message_code === 'bulk_purged' ) {
                $count = isset($_GET['processed_count']) ? absint($_GET['processed_count']) : 0;
                printf( '<div class="notice notice-success is-dismissible"><p>' . esc_html__('%d cardholder(s) purged.', 'fsbhoa-ac') . '</p></div>', $count );
            }
            ?>

            <?php
            switch ( $action ) {
                case 'preview_archived':
                    $this->render_preview_page();
                    break;
                case 'merge_cardholder':
                    // We will need to rename this view file as well.
                    require_once plugin_dir_path( __FILE__ ) . 'views/view-merge-archived-cardholder.php';
                    fsbhoa_render_merge_cardholder_view();
                    break;
                default:
                    $this->render_list_page();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Renders the main list view by loading the view file.
     */
    private function render_list_page() {
        // We will rename this view file in a subsequent step.
        require_once plugin_dir_path( __FILE__ ) . 'views/view-archived-cardholder-list.php';
        fsbhoa_render_archived_cardholder_list_view();
    }

    /**
     * Renders the read-only preview of a single archived cardholder.
     */
    private function render_preview_page() {
        global $wpdb;
        $cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;
        if ( ! $cardholder_id ) { return; }

        // UPDATED QUERY: Select from the main table where status is 'archived'.
        $cardholder = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM ac_cardholders WHERE id = %d AND cardholder_status = 'archived'", $cardholder_id ), ARRAY_A );
        
        if ( ! $cardholder ) {
             echo '<div class="notice notice-warning"><p>' . esc_html__( 'The requested archived cardholder could not be found. It may have already been restored or purged.', 'fsbhoa-ac' ) . '</p></div>';
             return;
        }

        $property_address = 'N/A';
        if (!empty($cardholder['property_id'])) {
            $property_address = $wpdb->get_var($wpdb->prepare("SELECT street_address FROM ac_property WHERE property_id = %d", $cardholder['property_id']));
        }

        echo '<h2>Preview: ' . esc_html( trim( ($cardholder['first_name'] ?? '') . ' ' . ($cardholder['last_name'] ?? '') ) ) . '</h2>';

        $this->render_card_preview_layout( $cardholder, $property_address );
    }



    /**
     * Renders the standard two-column card preview and details layout. (No changes needed here)
     */
    private function render_card_preview_layout( $cardholder, $property_address ) {
        // --- Prepare display variables ---
        $first_name = trim($cardholder['first_name'] ?? '');
        $last_name = trim($cardholder['last_name'] ?? '');
        $full_name = $first_name . ' ' . $last_name;
        $title = trim($cardholder['title'] ?? '');
        $photo_src = !empty($cardholder['photo']) ? 'data:image/jpeg;base64,' . base64_encode($cardholder['photo']) : '';
        $subtitle_text = '';
        if (!empty($title)) {
            $subtitle_text = $title;
        }

        ?>
        <div class="fsbhoa-print-page-wrapper">
            <div class="fsbhoa-print-columns">

                <div class="fsbhoa-card-preview-container">
                    <h3>Card Preview</h3>
                    <div class="id-card-container">
                        <div class="id-card-body">
                            <div class="id-card-photo">
                                <?php if ($photo_src): ?>
                                    <img src="<?php echo esc_attr($photo_src); ?>" alt="Cardholder Photo">
                                <?php endif; ?>
                            </div>
                            <div class="id-card-info">
                                <p class="card-name"><?php echo esc_html($first_name); ?></p>
                                <p class="card-name"><?php echo esc_html($last_name); ?></p>
                                <?php if (!empty($subtitle_text)): ?>
                                    <p class="card-subtitle"><?php echo esc_html($subtitle_text); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fsbhoa-cardholder-details-container">
                    <h3>Cardholder Details</h3>
                    <div class="details-box">
                        <p><strong>Name:</strong> <?php echo esc_html($full_name); ?></p>
                        <?php if (!empty($cardholder['import_first_name']) || !empty($cardholder['import_last_name'])): ?>
                            <p><strong>Formal Name:</strong> <?php echo esc_html(trim($cardholder['import_first_name'] . ' ' . $cardholder['import_last_name'])); ?></p>
                        <?php endif; ?>
                         <?php if (!empty($title)): ?>
                            <p><strong>Title:</strong> <?php echo esc_html($title); ?></p>
                        <?php endif; ?>
                        <p><strong>Address:</strong> <?php echo esc_html($property_address ?? 'N/A'); ?></p>
                        <?php
    global $wpdb;
    $creds = $wpdb->get_col($wpdb->prepare("SELECT CONCAT(credential_type, ': ', credential_value) FROM ac_credentials WHERE cardholder_id = %d", $cardholder['id']));
    $cred_string = empty($creds) ? 'N/A' : implode(', ', $creds);
?>
                        <p><strong>Credentials:</strong> <?php echo esc_html($cred_string); ?></p>
                        <p><strong>Phone:</strong> <?php echo esc_html( !empty($cardholder['phone']) ? $cardholder['phone'] . ' (' . $cardholder['phone_type'] . ')' : 'N/A' ); ?></p>
                        <p><strong>Email:</strong> <?php echo esc_html($cardholder['email'] ?? 'N/A'); ?></p>
                        <p><strong>Resident Type:</strong> <?php echo esc_html($cardholder['resident_type'] ?? 'N/A'); ?></p>
                    </div>
                    
                    <h3 style="margin-top: 1.5em;">Notes</h3>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="fsbhoa_update_archived_notes">
                        <input type="hidden" name="cardholder_id" value="<?php echo esc_attr($cardholder['id']); ?>">
                        <?php wp_nonce_field('fsbhoa_update_archived_notes_nonce'); ?>
                        
                        <textarea name="notes" rows="4" style="width: 100%;"><?php echo esc_textarea($cardholder['notes'] ?? ''); ?></textarea>
                        
                        <p class="submit" style="margin-top: 1em; padding-top: 0;">
                            <button type="submit" class="button button-primary">Save Notes</button>
                        </p>
                    </form>

                </div>

            </div>
        </div>
        <?php
    }
}


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
     * Renders the archive detail layout using plugin hooks.
     */
    private function render_card_preview_layout( $cardholder, $property_address ) {
        global $wpdb;

        $first_name = trim( $cardholder['first_name'] ?? '' );
        $last_name  = trim( $cardholder['last_name'] ?? '' );
        $full_name  = trim( $first_name . ' ' . $last_name );
        $title      = trim( $cardholder['title'] ?? '' );
        $company    = trim( $cardholder['company'] ?? '' );
        $is_vendor  = ( isset( $cardholder['cardholder_type'] ) && 'vendor' === $cardholder['cardholder_type'] );

        ?>
        <style>
            .fsbhoa-archive-columns {
                display: flex;
                gap: 30px;
                align-items: flex-start;
                margin-bottom: 2em;
            }
            .fsbhoa-archive-badge-col {
                flex: 0 0 240px;
            }
            .fsbhoa-archive-details-col {
                flex: 1;
            }
            .fsbhoa-details-box {
                background: #fdfdfd;
                border: 1px solid #ccd0d4;
                padding: 15px 20px;
                border-radius: 4px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            }
            .fsbhoa-details-box p {
                margin: 0 0 8px;
                font-size: 14px;
                line-height: 1.4;
            }
            .fsbhoa-details-box p:last-child {
                margin-bottom: 0;
            }
            @media (max-width: 600px) {
                .fsbhoa-archive-columns {
                    flex-direction: column;
                }
                .fsbhoa-archive-badge-col {
                    width: 100%;
                }
            }
        </style>

        <div class="fsbhoa-archive-columns">

            <!-- Left Column: Visual Badge (Hooked by Zebra) -->
            <div class="fsbhoa-archive-badge-col">
                <?php
                /**
                 * Hook for plugins to render a visual badge/card preview.
                 * Zebra hooks in here.
                 */
                do_action( 'fsbhoa_render_card_preview', $cardholder['id'], $cardholder );
                ?>
            </div>

            <!-- Right Column: Profile & Credentials -->
            <div class="fsbhoa-archive-details-col">
                <h3><?php esc_html_e( 'Cardholder Details', 'fsbhoa-ac' ); ?></h3>
                <div class="fsbhoa-details-box">
                    <p><strong><?php esc_html_e( 'Name:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $full_name ); ?></p>
                    <?php if ( ! empty( $cardholder['import_first_name'] ) || ! empty( $cardholder['import_last_name'] ) ) : ?>
                        <p><strong><?php esc_html_e( 'Formal Name:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( trim( $cardholder['import_first_name'] . ' ' . $cardholder['import_last_name'] ) ); ?></p>
                    <?php endif; ?>
                    <?php if ( ! empty( $title ) ) : ?>
                        <p><strong><?php esc_html_e( 'Title:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $title ); ?></p>
                    <?php endif; ?>
                    <?php if ( $is_vendor && ! empty( $company ) ) : ?>
                        <p><strong><?php esc_html_e( 'Company:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $company ); ?></p>
                    <?php else : ?>
                        <p><strong><?php esc_html_e( 'Address:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $property_address ?? 'N/A' ); ?></p>
                    <?php endif; ?>

                    <?php
                    $creds = $wpdb->get_col( $wpdb->prepare(
                        "SELECT CONCAT(credential_type, ': ', credential_value) FROM ac_credentials WHERE cardholder_id = %d",
                        $cardholder['id']
                    ) );
                    $cred_string = empty( $creds ) ? 'N/A' : implode( ', ', $creds );
                    ?>
                    <p><strong><?php esc_html_e( 'Credentials:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $cred_string ); ?></p>
                    <p><strong><?php esc_html_e( 'Phone:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( ! empty( $cardholder['phone'] ) ? $cardholder['phone'] . ' (' . ( $cardholder['phone_type'] ?? 'Mobile' ) . ')' : 'N/A' ); ?></p>
                    <p><strong><?php esc_html_e( 'Email:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( ! empty( $cardholder['email'] ) ? $cardholder['email'] : 'N/A' ); ?></p>
                    <p><strong><?php esc_html_e( 'Type:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $is_vendor ? 'Vendor / Staff' : ( $cardholder['resident_type'] ?? 'Resident' ) ); ?></p>

                    <?php
                    /**
                     * Hook for plugins to append details (e.g. DoorKing PIN status or access logs).
                     */
                    do_action( 'fsbhoa_archive_detail_extra_fields', $cardholder['id'], $cardholder );
                    ?>
                </div>

                <h3 style="margin-top: 1.5em;"><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></h3>
                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="fsbhoa_update_archived_notes">
                    <input type="hidden" name="cardholder_id" value="<?php echo esc_attr( $cardholder['id'] ); ?>">
                    <?php wp_nonce_field( 'fsbhoa_update_archived_notes_nonce' ); ?>

                    <textarea name="notes" rows="4" style="width: 100%;"><?php echo esc_textarea( $cardholder['notes'] ?? '' ); ?></textarea>

                    <p class="submit" style="margin-top: 1em; padding-top: 0;">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Notes', 'fsbhoa-ac' ); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}


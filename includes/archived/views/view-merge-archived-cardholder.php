<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the interface for merging an archived cardholder into a live one.
 */
function fsbhoa_render_merge_cardholder_view() {
    $source_id = isset($_GET['source_id']) ? absint($_GET['source_id']) : 0;
    if (!$source_id) {
        echo '<div class="notice notice-error"><p>No source cardholder specified for merge.</p></div>';
        return;
    }

    global $wpdb;
    // UPDATED QUERY: Select from the main table where status is 'archived'.
    $source_cardholder = $wpdb->get_row($wpdb->prepare(
        "SELECT c.*, p.street_address 
         FROM ac_cardholders c 
         LEFT JOIN ac_property p ON c.property_id = p.property_id 
         WHERE c.id = %d 
           AND c.cardholder_status = 'archived'
           AND c.cardholder_type = 'resident'", 
        $source_id
    ));

    if (!$source_cardholder) {
        echo '<div class="notice notice-error"><p>Archived cardholder not found.</p></div>';
        return;
    }
    ?>
    <div class="fsbhoa-merge-tool">
        <h2>Merge Archived Cardholder</h2>
        <p>This tool will copy key data (photo, preferred name, title, notes, RFID, etc.) from the archived "Source" record onto the live "Destination" record. It will then re-link all historical access logs and purge the source record.</p>

        <div class="merge-panels">
            <div class="merge-panel">
                <h3>Source Record (Archived)</h3>
                <div class="details-box">
                    <p><strong>Name:</strong> <?php echo esc_html($source_cardholder->first_name . ' ' . $source_cardholder->last_name); ?></p>
                    <p><strong>Formal Name:</strong> <?php echo esc_html($source_cardholder->import_first_name . ' ' . $source_cardholder->import_last_name); ?></p>
                    <p><strong>Address:</strong> <?php echo esc_html($source_cardholder->street_address ?? 'N/A'); ?></p>
                    <p><strong>Photo:</strong> <?php echo !empty($source_cardholder->photo) ? 'Yes' : 'No'; ?></p>
                    <p><strong>RFID:</strong> <?php echo esc_html($source_cardholder->rfid_id ?: 'None'); ?></p>
                    <p><strong>Notes:</strong> <?php echo esc_html($source_cardholder->notes); ?></p>
                </div>
            </div>

            <div class="merge-panel">
                <h3>Destination Record (Live)</h3>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="fsbhoa_confirm_merge">
                    <input type="hidden" name="source_cardholder_id" value="<?php echo esc_attr($source_id); ?>">
                    <input type="hidden" name="destination_cardholder_id" id="fsbhoa_destination_id_hidden" value="0">
                    <?php wp_nonce_field('fsbhoa_confirm_merge_nonce'); ?>

                    <div class="form-field">
                        <label for="fsbhoa_destination_search">Search for a live cardholder by name or address:</label>
                        <input type="text" id="fsbhoa_destination_search" placeholder="Start typing to search...">
                    </div>

                    <div id="fsbhoa_destination_details" class="details-box" style="display:none; margin-top: 1em;">
                        <!-- Details will be populated by JavaScript -->
                    </div>

                    <p class="submit">
                        <button type="submit" id="fsbhoa-confirm-merge-button" class="button button-primary" disabled>Confirm Merge</button>
                        <a href="<?php echo esc_url(remove_query_arg(['action', 'source_id'])); ?>" class="button button-secondary">Cancel</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
    <?php
}



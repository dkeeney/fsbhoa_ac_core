<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the front-end list of ARCHIVED cardholders using a custom HTML table.
 */
function fsbhoa_render_archived_cardholder_list_view() {
    global $wpdb;

    $archived_cardholders = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.id, c.first_name, c.last_name, c.email, c.deleted_at, c.notes, c.origin, p.street_address,
                    (c.photo IS NOT NULL AND LENGTH(c.photo) > 0) AS has_photo
             FROM ac_cardholders c
             LEFT JOIN ac_property p ON c.property_id = p.property_id
             WHERE c.cardholder_status = %s
               AND c.cardholder_type = 'resident'
             ORDER BY c.deleted_at DESC",
            'archived'
        ),
        ARRAY_A
    );

    if ( $wpdb->last_error ) {
        echo '<div class="notice notice-error"><p><strong>Database Error:</strong> Could not retrieve archived cardholders. ' . esc_html( $wpdb->last_error ) . '</p></div>';
        return;
    }

    $current_page_url = remove_query_arg('view');
    ?>
    <form id="fsbhoa-bulk-archived-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="fsbhoa_bulk_archived_action">
        <?php wp_nonce_field('fsbhoa_bulk_archived_nonce', '_wpnonce'); ?>

        <div class="fsbhoa-table-controls" style="margin-bottom: 1em;">
            <!-- Left Side: Bulk Actions -->
            <div style="display: flex; align-items: center; gap: 5px;">
                <select name="bulk_action" id="archived-bulk-action-selector">
                    <option value="-1">Bulk Actions</option>
                    <option value="restore">Restore Selected</option>
                    <option value="purge">Purge Selected</option>
                </select>
                <input type="submit" id="doaction_archived" class="button action" value="Apply">
            </div>

            <!-- Right Side: Show Entries & Search -->
            <div style="display: flex; align-items: center; gap: 15px;">
                <div class="fsbhoa-control-group">
                    <label for="fsbhoa-archived-custom-length-menu">Show</label>
                    <select name="fsbhoa-archived-custom-length-menu" id="fsbhoa-archived-custom-length-menu">
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                        <option value="-1">All</option>
                    </select>
                    <span>entries</span>
                </div>
                <div class="fsbhoa-control-group">
                    <label for="fsbhoa-archived-cardholder-search-input">Search:</label>
                    <input type="search" id="fsbhoa-archived-cardholder-search-input" placeholder="Search archived...">
                </div>
            </div>
        </div>

        <table id="fsbhoa-archived-cardholder-table" class="display" style="width:100%">
            <thead>
                <tr>
                    <th class="no-sort fsbhoa-checkbox-column"><input type="checkbox" id="cb-select-all-archived"></th>
                    <th class="no-sort fsbhoa-actions-column"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Property', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Date Archived', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty($archived_cardholders) ) : foreach ( $archived_cardholders as $cardholder ) : ?>
                    <?php
                        $row_classes = '';
                        if (isset($cardholder['origin']) && $cardholder['origin'] === 'manual') {
                            $row_classes = 'fsbhoa-manual-record';
                        }
                    ?>
                    <tr class="<?php echo esc_attr($row_classes); ?>" data-cardholder-id="<?php echo esc_attr($cardholder['id']); ?>">
                        <td class="fsbhoa-checkbox-column">
                            <input type="checkbox" name="cardholder_ids[]" value="<?php echo esc_attr($cardholder['id']); ?>" class="archived-cb">
                        </th>
                        <td class="fsbhoa-actions-column">
                            <?php
                            $restore_nonce = wp_create_nonce( 'fsbhoa_restore_archived_cardholder_' . $cardholder['id'] );
                            $restore_url = add_query_arg(['action' => 'fsbhoa_restore_archived_cardholder', 'cardholder_id' => absint( $cardholder['id'] ), '_wpnonce' => $restore_nonce], admin_url( 'admin-post.php' ));
                            $preview_url = add_query_arg(['action' => 'preview_archived', 'cardholder_id' => absint( $cardholder['id'] )], $current_page_url);
                            $merge_url = add_query_arg(['action' => 'merge_cardholder', 'source_id' => absint($cardholder['id'])], $current_page_url);
                            $purge_nonce = wp_create_nonce('fsbhoa_purge_cardholder_' . $cardholder['id']);
                            $purge_url = add_query_arg(['action' => 'fsbhoa_purge_archived_cardholder', 'cardholder_id' => absint($cardholder['id']), '_wpnonce' => $purge_nonce], admin_url('admin-post.php'));
                            ?>
                            <a href="<?php echo esc_url($preview_url); ?>" class="fsbhoa-action-icon" title="Preview Archived Record">
                                <span class="dashicons dashicons-visibility"></span>
                            </a>
                            <a href="<?php echo esc_url($restore_url); ?>" class="fsbhoa-action-icon" title="Restore Cardholder" onclick="return confirm('Are you sure you want to restore this cardholder?');">
                                <span class="dashicons dashicons-undo"></span>
                            </a>
                            <a href="<?php echo esc_url($merge_url); ?>" class="fsbhoa-action-icon" title="Merge this record into a live record">
                                <span class="dashicons dashicons-controls-repeat"></span>
                            </a>
                            <a href="<?php echo esc_url($purge_url); ?>" class="fsbhoa-action-icon" title="Purge Record (Hide from view)" onclick="return confirm('WARNING: This will hide the record from this list. It will be retained for historical reports but cannot be restored. Are you sure?');">
                                <span class="dashicons dashicons-trash" style="color: #d63638;"></span>
                            </a>
                        </td>
                        <td>
                            <strong><?php echo esc_html( $cardholder['first_name'] . ' ' . $cardholder['last_name'] ); ?></strong>
                            <?php if ( !empty($cardholder['has_photo']) ) : ?>
                                <span class="dashicons dashicons-camera" title="Has Photo" style="font-size: 16px; color: #888; vertical-align: text-bottom; margin-left: 6px;"></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $cardholder['street_address'] ?? 'N/A' ); ?></td>
                        <td><?php echo esc_html( $cardholder['email'] ); ?></td>
                        <td>
                            <?php
                                // Print date and time on separate lines for better horizontal fit
                                echo !empty($cardholder['deleted_at'])
                                    ? esc_html( date( 'Y-m-d', strtotime( $cardholder['deleted_at'] ) ) ) . '<br>' . esc_html( date( 'H:i:s', strtotime( $cardholder['deleted_at'] ) ) )
                                    : 'N/A';
                            ?>
                        </td>
                        <td class="notes-cell" data-cardholder-id="<?php echo esc_attr($cardholder['id']); ?>">
                            <div class="notes-text-container">
                                <span class="notes-text"><?php echo esc_html( $cardholder['notes'] ); ?></span>
                            </div>
                            <button type="button" class="edit-notes-button button-link" title="Edit Notes">
                                <span class="dashicons dashicons-edit"></span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach;
                  endif;
                ?>
            </tbody>
        </table>
    </form>
    <div id="fsbhoa-notes-editor-dialog" title="Edit Notes" style="display:none;">
        <p><strong>Cardholder:</strong> <span id="notes-editor-cardholder-name"></span></p>
        <textarea id="notes-editor-textarea" rows="8" style="width: 100%; font-family: monospace;"></textarea>
        <input type="hidden" id="notes-editor-cardholder-id" value="">
    </div>
<?php
}


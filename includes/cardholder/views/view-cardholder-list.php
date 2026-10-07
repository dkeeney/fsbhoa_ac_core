<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the front-end list of cardholders using a custom HTML table enhanced by DataTables.
 */
function fsbhoa_render_cardholder_list_view() {
    // 1. INJECT CSS FIX TO KILL 404s
    ?>
    <style>
        /* Override vendor CSS to stop looking for missing PNGs */
        table.dataTable thead th,
        table.dataTable thead td {
            background-image: none !important;
        }
        /* DataTables 2.0+ uses SVG/pseudo-elements now anyway, so this is safe */
    </style>
    <?php
    $cardholders =  Fsbhoa_Cardholder_Admin_Page::get_cardholders(999, 1, 'last_name', 'asc');
    $current_page_url = get_permalink();
    ?>
    <div id="fsbhoa-cardholder-management-wrap" class="fsbhoa-frontend-wrap" data-export-nonce="<?php echo wp_create_nonce('fsbhoa_export_nonce'); ?>" data-print-nonce="<?php echo wp_create_nonce('fsbhoa_print_report_nonce'); ?>">
        <h1><?php echo esc_html__( 'Cardholder Management', 'fsbhoa-ac' ); ?></h1>

        <?php if (isset($_GET['message'])) : ?>
            <div class="notice notice-success is-dismissible" style="border-left-color: #4CAF50; padding: 1em; margin-bottom: 1em;">
                <p>
                    <?php
                    if ($_GET['message'] === 'cardholder_updated') {
                        esc_html_e('Cardholder updated successfully.', 'fsbhoa-ac');
                    } elseif ($_GET['message'] === 'cardholder_added') {
                        esc_html_e('Cardholder added successfully.', 'fsbhoa-ac');
                    } elseif ($_GET['message'] === 'cardholder_deleted_successfully') {
                        esc_html_e('Cardholder archived.', 'fsbhoa-ac');
                    } elseif ($_GET['message'] === 'bulk_archived') {
                        $count = isset($_GET['archived_count']) ? absint($_GET['archived_count']) : 0;
                        printf( esc_html__('%d cardholder(s) archived successfully.', 'fsbhoa-ac'), $count );
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <form id="fsbhoa-bulk-action-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" id="bulk-action-hidden-input" value="fsbhoa_bulk_cardholder_action">
            <?php wp_nonce_field('fsbhoa_bulk_cardholder_nonce', '_wpnonce'); ?>

            <!-- Custom HTML Control Bar -->
            <div class="fsbhoa-table-controls">
                <!-- Left Side: Add New Button & Actions -->
                <div style="display: flex; align-items: center; gap: 5px;">
                    <a href="<?php echo esc_url( add_query_arg('action', 'add', $current_page_url) ); ?>" class="button button-primary">
                        <?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg('view', 'properties', $current_page_url) ); ?>" class="button button-secondary">
                            <?php echo esc_html__( 'Manage Properties', 'fsbhoa-ac' ); ?>
                    </a>

                    <span style="border-left: 1px solid #ccc; height: 24px; margin: 0 5px;"></span> <!-- Separator -->

                    <select name="bulk_action" id="bulk-action-selector">
                        <option value="-1">Bulk Actions</option>
                        <option value="archive">Archive Selected</option>
                        <option value="print">Print Selected</option>
                        <option value="export">Export Selected (.csv)</option>
                    </select>
                    <input type="submit" id="doaction" class="button action" value="Apply">

                    <span id="fsbhoa-sync-status" style="margin-left: 10px; font-style: italic;"></span>
                </div>

                <!-- Right Side: Container for Entries and Search -->
                <div class="fsbhoa-table-right-controls">
                    <div class="fsbhoa-control-group">
                        <label for="fsbhoa-custom-length-menu">Show</label>
                        <select name="fsbhoa-custom-length-menu" id="fsbhoa-custom-length-menu">
                            <option value="100">100</option>
                            <option value="250">250</option>
                            <option value="500">500</option>
                            <option value="-1">All</option>
                        </select>
                        <span>entries</span>
                    </div>
                    <div class="fsbhoa-control-group">
                         <label for="fsbhoa-cardholder-search-input">Search:</label>
                        <input type="search" id="fsbhoa-cardholder-search-input" placeholder="Search...">
                    </div>
                </div>
            </div>
            <!-- End Custom HTML Control Bar -->

            <table id="fsbhoa-cardholder-table" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th class="no-sort fsbhoa-checkbox-column">
                            <input type="checkbox" id="cb-select-all">
                        </th>
                        <th class="no-sort fsbhoa-actions-column"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Property', 'fsbhoa-ac' ); ?></th>
                        <th class="sorting fsbhoa-status-column"><?php esc_html_e( 'Card Status', 'fsbhoa-ac' ); ?></th>
                        <th class="sorting fsbhoa-type-column" title="<?php esc_attr_e( 'Cardholder Type', 'fsbhoa-ac' ); ?>">
                            <?php esc_html_e( 'Type', 'fsbhoa-ac' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty($cardholders) ) : foreach ( $cardholders as $cardholder ) : ?>
                        <?php
                            $row_classes = '';
                            if (isset($cardholder['origin']) && $cardholder['origin'] === 'manual') {
                                $row_classes = 'fsbhoa-manual-record';
                            }
                            global $wpdb;
                            $all_creds = $wpdb->get_col($wpdb->prepare("SELECT credential_value FROM ac_credentials WHERE cardholder_id = %d", $cardholder['id']));
                        ?>
                        <tr class="<?php echo esc_attr($row_classes); ?>" data-cardholder-id="<?php echo esc_attr($cardholder['id']); ?>">

                            <td class="fsbhoa-checkbox-column">
                                <input type="checkbox" name="cardholder_ids[]" value="<?php echo esc_attr($cardholder['id']); ?>" class="cardholder-cb">
                            </td>
                            <td class="fsbhoa-actions-column">
                                <?php
                                $edit_url = add_query_arg(array('action' => 'edit_cardholder', 'cardholder_id' => absint($cardholder['id'])), $current_page_url);
                                $delete_nonce = wp_create_nonce('fsbhoa_delete_cardholder_nonce_' . $cardholder['id']);
                                $delete_url = add_query_arg(array('action'=> 'fsbhoa_delete_cardholder', 'cardholder_id' => absint($cardholder['id']), '_wpnonce'=> $delete_nonce), admin_url('admin-post.php'));
                                $print_page_url = get_permalink(get_page_by_path('print-photo-id'));
                                $print_url = add_query_arg(array('action' => 'print_card', 'cardholder_id' => absint($cardholder['id'])), $print_page_url);
                                ?>
                                <a href="<?php echo esc_url($edit_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Edit Cardholder', 'fsbhoa-ac'); ?>">
                                    <span class="dashicons dashicons-edit"></span>
                                </a>
                                <a href="<?php echo esc_url($print_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Print ID Card', 'fsbhoa-ac'); ?>">
                                   <span class="dashicons dashicons-printer"></span>
                                </a>
                                <?php
                                // Extension plugins add row action icons here.
                                do_action( 'fsbhoa_cardholder_list_action_icons', $cardholder );
                                ?>
                                <a href="<?php echo esc_url($delete_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Archive Cardholder', 'fsbhoa-ac'); ?>" onclick="return confirm('Are you sure you want to archive this cardholder?');">
                                    <span class="dashicons dashicons-archive"></span>
                                </a>
                            </td>

                            <?php
                                $display_name = trim( $cardholder['first_name'] . ' ' . $cardholder['last_name'] );
                                $sort_name    = trim( ($cardholder['last_name'] ?? '') . ' ' . ($cardholder['first_name'] ?? '') );

                                // Prepare clean search string for DataTables without polluting the visible DOM text
                                $search_tokens = [];
                                if ( ! empty( $all_creds ) ) {
                                    $search_tokens[] = implode( ' ', $all_creds );
                                }
                                if ( ! empty( $cardholder['title'] ) ) {
                                    $search_tokens[] = $cardholder['title'];
                                }
                                $name_search = trim( $display_name . ' ' . implode( ' ', $search_tokens ) );
                            ?>
                            <td data-order="<?php echo esc_attr( $sort_name ); ?>" data-search="<?php echo esc_attr( $name_search ); ?>"><strong><?php echo esc_html( $display_name ); ?></strong></td>
                            <?php
                                $address_display = trim( ($cardholder['house_number'] ?? '') . ' ' . ($cardholder['street_name'] ?? '') );
                                $sort_address    = ($cardholder['street_name'] ?? '') . str_pad( ($cardholder['house_number'] ?? 0), 10, '0', STR_PAD_LEFT );
                            ?>
                            <td data-order="<?php echo esc_attr( $sort_address ); ?>"><?php echo ! empty( $address_display ) ? esc_html( $address_display ) : '<em>N/A</em>'; ?></td>
                            <td class="fsbhoa-status-column">
                                <div style="display: flex; align-items: center; justify-content: flex-start; gap: 8px;">
                                    <div class="fsbhoa-status-indicators" style="display: inline-flex; align-items: center; gap: 4px;">
                                        <?php
                                        // 1. Vehicle Icon (Core manages vehicles)
                                        $has_vehicle = false;
                                        if ( ! empty( $cardholder['household_id'] ) ) {
                                            $has_vehicle = (bool) $wpdb->get_var( $wpdb->prepare(
                                                "SELECT 1 FROM ac_vehicles WHERE household_id = %d LIMIT 1",
                                                $cardholder['household_id']
                                            ) );
                                        }
                                        if ( $has_vehicle ) : ?>
                                            <span class="dashicons dashicons-car" title="<?php esc_attr_e( 'Household has registered vehicle(s)', 'fsbhoa-ac' ); ?>" style="font-size: 16px; width: 16px; height: 16px; color: #2271b1;"></span>
                                        <?php else : ?>
                                            <span style="display: inline-block; width: 16px;"></span>
                                        <?php endif; ?>

                                        <?php
                                        // 2. Photo Icon (Core manages cardholder photo blob)
                                        $has_photo = ! empty( $cardholder['photo'] );
                                        if ( $has_photo ) : ?>
                                            <span class="dashicons dashicons-camera" title="<?php esc_attr_e( 'Photo on file', 'fsbhoa-ac' ); ?>" style="font-size: 16px; width: 16px; height: 16px; color: #008a20;"></span>
                                        <?php else : ?>
                                            <span style="display: inline-block; width: 16px;"></span>
                                        <?php endif; ?>

                                        <?php
                                        // 3. Subordinate Plugin Hook (DoorKing, UHPPOTE, etc.)
                                        do_action( 'fsbhoa_cardholder_list_status_icons', $cardholder );
                                        ?>
                                    </div>

                                    <span><?php echo esc_html( ucwords( $cardholder['cardholder_status'] ) ); ?></span>
                                </div>
                            </td>
                            <td class="fsbhoa-type-column">
                                <?php echo esc_html( $cardholder['resident_type'] ?? '' ); ?>
                            </td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No cardholders found.', 'fsbhoa-ac' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>
    </div>
    <?php
}


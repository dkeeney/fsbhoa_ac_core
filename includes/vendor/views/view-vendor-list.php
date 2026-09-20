<?php
if ( ! defined( 'WPINC' ) ) {
    die;
}

function fsbhoa_get_vendors() {
    global $wpdb;

    // Exclude automated rotation dummy cardholders from the vendor list table
    $sql = "SELECT c.*,
                   (SELECT COUNT(*) FROM ac_credentials cr WHERE cr.cardholder_id = c.id AND cr.status = 'active') AS active_cred_count,
                   (SELECT GROUP_CONCAT(credential_value SEPARATOR ' ') FROM ac_credentials cr WHERE cr.cardholder_id = c.id) AS all_creds
            FROM ac_cardholders c
            WHERE c.cardholder_type = 'vendor'
              AND c.cardholder_status NOT IN ('archived', 'purged')
              AND (c.company IS NULL OR c.company NOT LIKE 'FSB Community Vendor Access%')
            ORDER BY c.company ASC, c.last_name ASC";

    return $wpdb->get_results( $sql, ARRAY_A );
}

function fsbhoa_render_vendor_rotation_banner() {
    if ( ! class_exists( 'Fsbhoa_DoorKing_Rotation' ) ) {
        return;
    }

    $rotation    = new Fsbhoa_DoorKing_Rotation();
    $slot_ids    = $rotation->get_vendor_record_ids();
    $curr_code   = $rotation->get_credential_value( $slot_ids['current'] );
    $curr_status = $rotation->get_credential_status( $slot_ids['current'] );
    $prev_code   = $rotation->get_credential_value( $slot_ids['previous'] );
    $prev_status = $rotation->get_credential_status( $slot_ids['previous'] );
    $is_auto     = ( get_option( 'fsbhoa_dk_enable_rotation', '0' ) === '1' );

    $current_month = current_time( 'F' );
    $prev_month    = date( 'F', strtotime( '-1 month', current_time( 'timestamp' ) ) );
    ?>
    <div style="background: #ffffff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 18px; margin-bottom: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
        <div>
            <div style="font-weight: 600; font-size: 14px; color: #1d2327; margin-bottom: 3px;">
                Community Rotating Vendor Code
                <span style="font-size: 11px; font-weight: normal; background: <?php echo $is_auto ? '#e6f4ea' : '#f0f0f1'; ?>; color: <?php echo $is_auto ? '#137333' : '#666'; ?>; border: 1px solid <?php echo $is_auto ? '#137333' : '#ccd0d4'; ?>; padding: 2px 6px; border-radius: 3px; margin-left: 6px;">
                    <?php echo $is_auto ? 'Auto-Rotation ON' : 'Manual Mode'; ?>
                </span>
            </div>
            <div style="font-size: 12px; color: #50575e;">
                Shared gate code used by general contractors, couriers, and unscheduled service vehicles.
            </div>
        </div>

        <div style="display: flex; gap: 24px; align-items: center;">
            <div style="text-align: right;">
                <span style="display: block; font-size: 11px; text-transform: uppercase; color: #646970; font-weight: 600;">
                    <?php echo esc_html( $current_month ); ?> (Active)
                </span>
                <span style="font-family: monospace; font-size: 18px; font-weight: bold; color: #137333; letter-spacing: 2px;">
                    #<?php echo esc_html( $curr_code ?: 'None' ); ?>
                </span>
            </div>

            <?php if ( $is_auto && ! empty( $prev_code ) && 'active' === $prev_status ) : ?>
                <div style="text-align: right; border-left: 1px solid #dcdcde; padding-left: 20px;">
                    <span style="display: block; font-size: 11px; text-transform: uppercase; color: #646970; font-weight: 600;">
                        <?php echo esc_html( $prev_month ); ?> (Grace)
                    </span>
                    <span style="font-family: monospace; font-size: 16px; color: #646970; letter-spacing: 2px;">
                        #<?php echo esc_html( $prev_code ); ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <div style="border-left: 1px solid #dcdcde; padding-left: 15px;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fsbhoa_doorking_settings' ) ); ?>" class="button button-secondary" style="font-size: 12px;">
                        Configure
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function fsbhoa_render_vendor_list_view() {
    ?>
    <style>
        table.dataTable thead th,
        table.dataTable thead td {
            background-image: none !important;
        }
    </style>
    <?php
    $vendors          = fsbhoa_get_vendors();
    $current_page_url = get_permalink();
    ?>
    <div id="fsbhoa-cardholder-management-wrap" class="fsbhoa-frontend-wrap" data-export-nonce="<?php echo wp_create_nonce( 'fsbhoa_export_nonce' ); ?>" data-print-nonce="<?php echo wp_create_nonce( 'fsbhoa_print_report_nonce' ); ?>">
        <h1><?php echo esc_html__( 'Vendor & Staff Management', 'fsbhoa-ac' ); ?></h1>

        <!-- Community Vendor Live Rotation Status Banner -->
        <?php fsbhoa_render_vendor_rotation_banner(); ?>

        <?php if ( isset( $_GET['message'] ) ) : ?>
            <div class="notice notice-success is-dismissible" style="border-left-color: #4CAF50; padding: 1em; margin-bottom: 1em;">
                <p>
                    <?php
                    if ( 'vendor_updated' === $_GET['message'] ) {
                        esc_html_e( 'Vendor updated successfully.', 'fsbhoa-ac' );
                    } elseif ( 'vendor_added' === $_GET['message'] ) {
                        esc_html_e( 'Vendor added successfully.', 'fsbhoa-ac' );
                    } elseif ( 'vendor_purged' === $_GET['message'] ) {
                        esc_html_e( 'Vendor purged successfully.', 'fsbhoa-ac' );
                    } elseif ( 'vendor_deleted' === $_GET['message'] ) {
                        esc_html_e( 'Vendor deleted successfully.', 'fsbhoa-ac' );
                    } elseif ( 'bulk_purged' === $_GET['message'] ) {
                        $count = isset( $_GET['purged_count'] ) ? absint( $_GET['purged_count'] ) : 0;
                        printf( esc_html__( '%d vendor(s) purged successfully.', 'fsbhoa-ac' ), $count );
                    } elseif ( 'bulk_deleted' === $_GET['message'] ) {
                        $count = isset( $_GET['deleted_count'] ) ? absint( $_GET['deleted_count'] ) : 0;
                        printf( esc_html__( '%d vendor(s) deleted successfully.', 'fsbhoa-ac' ), $count );
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <form id="fsbhoa-bulk-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" id="bulk-action-hidden-input" value="fsbhoa_bulk_vendor_action">
            <?php wp_nonce_field( 'fsbhoa_bulk_vendor_nonce', '_wpnonce' ); ?>

            <div class="fsbhoa-table-controls">
                <div style="display: flex; align-items: center; gap: 5px;">
                    <a href="<?php echo esc_url( add_query_arg( 'action', 'add', $current_page_url ) ); ?>" class="button button-primary">
                        <?php echo esc_html__( 'Add New Vendor', 'fsbhoa-ac' ); ?>
                    </a>

                    <span style="border-left: 1px solid #ccc; height: 24px; margin: 0 5px;"></span>

                    <select name="bulk_action" id="bulk-action-selector">
                        <option value="-1"><?php esc_html_e( 'Bulk Actions', 'fsbhoa-ac' ); ?></option>
                        <option value="purge"><?php esc_html_e( 'Purge Selected', 'fsbhoa-ac' ); ?></option>
                        <!-- Note: "delete" is not hooked up right now.  For future use. -->
                        <option value="print"><?php esc_html_e( 'Print Selected', 'fsbhoa-ac' ); ?></option>
                        <option value="export"><?php esc_html_e( 'Export Selected (.csv)', 'fsbhoa-ac' ); ?></option>
                    </select>
                    <input type="submit" id="doaction" class="button action" value="<?php esc_attr_e( 'Apply', 'fsbhoa-ac' ); ?>">
                </div>

                <div class="fsbhoa-table-right-controls">
                    <div class="fsbhoa-control-group">
                        <label for="fsbhoa-custom-length-menu"><?php esc_html_e( 'Show', 'fsbhoa-ac' ); ?></label>
                        <select name="fsbhoa-custom-length-menu" id="fsbhoa-custom-length-menu">
                            <option value="100">100</option>
                            <option value="250">250</option>
                            <option value="500">500</option>
                            <option value="-1"><?php esc_html_e( 'All', 'fsbhoa-ac' ); ?></option>
                        </select>
                        <span><?php esc_html_e( 'entries', 'fsbhoa-ac' ); ?></span>
                    </div>
                    <div class="fsbhoa-control-group">
                        <label for="fsbhoa-cardholder-search-input"><?php esc_html_e( 'Search:', 'fsbhoa-ac' ); ?></label>
                        <input type="search" id="fsbhoa-cardholder-search-input" placeholder="<?php esc_attr_e( 'Search...', 'fsbhoa-ac' ); ?>">
                    </div>
                </div>
            </div>

            <table id="fsbhoa-cardholder-table" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th class="no-sort fsbhoa-checkbox-column">
                            <input type="checkbox" id="cb-select-all">
                        </th>
                        <th class="no-sort fsbhoa-actions-column"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Company / Organization', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Category', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Contact Name', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Phone', 'fsbhoa-ac' ); ?></th>
                        <th class="sorting fsbhoa-status-column"><?php esc_html_e( 'Status', 'fsbhoa-ac' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $vendors ) ) : foreach ( $vendors as $vendor ) : ?>
                        <?php
                            $edit_url       = add_query_arg( array( 'action' => 'edit_vendor', 'id' => absint( $vendor['id'] ) ), $current_page_url );
                            $purge_nonce   = wp_create_nonce( 'fsbhoa_purge_vendor_nonce_' . $vendor['id'] );
                            $purge_url     = add_query_arg( array( 'action' => 'fsbhoa_purge_vendor', 'vendor_id' => absint( $vendor['id'] ), '_wpnonce' => $purge_nonce ), admin_url( 'admin-post.php' ) );

                            $print_report_url = add_query_arg( array(
                                'action'       => 'fsbhoa_print_report',
                                'selected_ids' => absint( $vendor['id'] ),
                                '_wpnonce'     => wp_create_nonce( 'fsbhoa_print_report_nonce' ),
                            ), admin_url( 'admin-post.php' ) );

                            $first        = trim( $vendor['first_name'] ?? '' );
                            $last         = trim( $vendor['last_name'] ?? '' );
                            $contact_name = ( $first || $last ) ? trim( $first . ' ' . $last ) : '—';
                            $company = ! empty( $vendor['company'] ) ? $vendor['company'] : '';
                            $category     = ! empty( $vendor['resident_type'] ) ? $vendor['resident_type'] : 'General Contractor';
                        ?>
                        <tr data-cardholder-id="<?php echo esc_attr( $vendor['id'] ); ?>">
                            <td class="fsbhoa-checkbox-column">
                                <input type="checkbox" name="cardholder_ids[]" value="<?php echo esc_attr( $vendor['id'] ); ?>" class="cardholder-cb">
                            </td>
                            <td class="fsbhoa-actions-column">
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e( 'Edit Vendor', 'fsbhoa-ac' ); ?>">
                                    <span class="dashicons dashicons-edit"></span>
                                </a>
                                <a href="<?php echo esc_url( $print_report_url ); ?>" target="_blank" class="fsbhoa-action-icon" title="<?php esc_attr_e( 'Print Vendor Details', 'fsbhoa-ac' ); ?>">
                                    <span class="dashicons dashicons-printer"></span>
                                </a>
                                <a href="<?php echo esc_url( $purge_url ); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e( 'Purge Vendor', 'fsbhoa-ac' ); ?>" onclick="return confirm('Are you sure you want to purge this vendor and all associated vehicles and keycards? This cannot be undone.');">
                                    <span class="dashicons dashicons-trash" style="color:#d63638;"></span>
                                </a>
                            </td>
                            <td>
                                <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $company ); ?></a></strong>
                                <?php if ( ! empty( $vendor['all_creds'] ) ) : ?>
                                    <span class="fsbhoa-visually-hidden">Keys: <?php echo esc_html( $vendor['all_creds'] ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge" style="background:#f0f0f1; padding:3px 8px; border-radius:3px; font-size:12px; font-weight:500; border:1px solid #ccd0d4;"><?php echo esc_html( $category ); ?></span></td>
                            <td><?php echo esc_html( $contact_name ); ?></td>
                            <td><?php echo ! empty( $vendor['phone'] ) ? esc_html( $vendor['phone'] ) : '—'; ?></td>
                            <td class="fsbhoa-status-column"><?php echo esc_html( ucwords( $vendor['cardholder_status'] ) ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>
    </div>
    <?php
}


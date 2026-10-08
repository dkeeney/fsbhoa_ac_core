<?php
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Fetches unified data and renders a read-only Cardholder Summary Card.
 * Uses WordPress action hooks so UHPPOTE and DoorKing plugins can 
 * inject their specific credentials (Directory Code, Gate Code, Windshield Tags, etc).
 */
function fsbhoa_render_cardholder_summary_card( $cardholder_id, $echo = true ) {
    global $wpdb;

    $cardholder_id = absint( $cardholder_id );
    if ( ! $cardholder_id ) {
        $error_html = '<div class="notice notice-error"><p>' . esc_html__( 'Invalid cardholder ID.', 'fsbhoa-ac' ) . '</p></div>';
        if ( $echo ) { echo $error_html; return; }
        return $error_html;
    }

    // 1. Fetch Cardholder, Property, and Household details
    $ch = $wpdb->get_row( $wpdb->prepare(
        "SELECT c.*, p.street_address, p.house_number, p.street_name, 
                 h.household_name, h.primary_cardholder_id
         FROM ac_cardholders c
         LEFT JOIN ac_property p ON c.property_id = p.property_id
         LEFT JOIN ac_households h ON c.household_id = h.household_id
         WHERE c.id = %d",
        $cardholder_id
    ), ARRAY_A );

    if ( ! $ch ) {
        $error_html = '<div class="notice notice-error"><p>' . esc_html__( 'Cardholder not found.', 'fsbhoa-ac' ) . '</p></div>';
        if ( $echo ) { echo $error_html; return; }
        return $error_html;
    }

    // 3. Fetch Registered Vehicles
    $vehicles = [];
    if ( ! empty( $ch['household_id'] ) ) {
        $vehicles = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM ac_vehicles WHERE household_id = %d ORDER BY vehicle_id ASC",
            $ch['household_id']
        ), ARRAY_A );
    }

    // 4. Fetch Baseline Credentials (Core schema)
    $credentials = $wpdb->get_results( $wpdb->prepare(
        "SELECT credential_type, credential_value, status, issue_date 
         FROM ac_credentials 
         WHERE cardholder_id = %d 
         ORDER BY id ASC",
        $cardholder_id
    ), ARRAY_A );

    // 5. Permission Groups
    $groups = $wpdb->get_results( $wpdb->prepare(
        "SELECT g.group_name 
         FROM ac_cardholder_groups cg
         JOIN ac_groups g ON cg.group_id = g.group_id
         WHERE cg.cardholder_id = %d 
         ORDER BY g.group_name ASC",
        $cardholder_id
    ), ARRAY_A );

    // Formatting
    $full_name = trim( ( $ch['first_name'] ?? '' ) . ' ' . ( $ch['last_name'] ?? '' ) );
    $formal_name = trim( ( $ch['import_first_name'] ?? '' ) . ' ' . ( $ch['import_last_name'] ?? '' ) );
    $status = strtolower( $ch['cardholder_status'] ?? 'active' );
    $is_editable = ! in_array( $status, [ 'archived', 'purged' ], true );

    $fallback_svg = "data:image/svg+xml;base64,PH9jZyB4bWxucyBzeXN0ZWQgdGhlIGZ1bGwgTm91YWxsIHtpdGhlPTo0LjQ5ZTowLjI0NyA5Lm4wMiA0MSAyMiAwIGZpbGwgPyIzM3NzMzMgJmN1cnJlbnQgPyIxMyAwLTIuLjU2OCA2MDE2LjY2NSAxMS4wLTEyLjI0NzA5LjIuNTMwLjEuOTAyMCA2MjYsMTEuODU2MDEuOTAyMCA2MjYsMTEuODU2ODY2LjYsIDEuOTAzMDA4LjI0NzA5LjIuNTMwLTEuLjg1NIAyMS4wLTEuODU2ODEuOTAzLTEuOTAzMDA4LjI0NzA5IiB+Pjwvc3ZnJg==";
    $photo_src = ! empty( $ch['photo'] ) ? 'data:image/jpeg;base64,' . base64_encode( $ch['photo'] ) : $fallback_svg;

    $status_badges = [
        'active'   => 'background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc;',
        'archived' => 'background: #e2e3e5; color: #41464b; border: 1px solid #d3d6d8;',
        'purged'   => 'background: #f8d7da; color: #842029; border: 1px solid #f5c2c7;',
    ];
    $status_style = $status_badges[ $status ] ?? $status_badges['archived'];

    $edit_url = esc_url( home_url( '/cardholder/?action=edit_cardholder&cardholder_id=' . $cardholder_id ) );

    ob_start();
    ?>

    <div class="fsbhoa-summary-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); max-width: 760px; margin: 0 auto; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;">

        <!-- Top Header -->
        <div style="display: flex; gap: 20px; padding: 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa; align-items: flex-start;">
            <div style="flex-shrink: 0;">
                <img src="<?php echo esc_attr( $photo_src ); ?>" alt="<?php echo esc_attr( $full_name ); ?>" style="width: 120px; height: 150px; object-fit: cover; border-radius: 6px; border: 1px solid #ccd0d4; background: #eee; display: block;">
            </div>

            <div style="flex-grow: 1;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
                    <div>
                        <h2 style="margin: 0; font-size: 22px; font-weight: 700; color: #1d2327;">
                            <?php echo esc_html( $full_name ); ?>
                            <?php if ( ! empty( $ch['title'] ) ) : ?>
                                <span style="font-size: 14px; font-weight: 400; color: #646970;">(<?php echo esc_html( $ch['title'] ); ?>)</span>
                            <?php endif; ?>
                    </h2>
                    <?php if ( ! empty( $formal_name ) ) : ?>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #646970; font-style: italic;">
                            <?php esc_html_e( 'Formal Name:', 'fsbhoa-ac' ); ?> <?php echo esc_html( $formal_name ); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <span style="display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; <?php echo esc_attr( $status_style ); ?>">
                    <?php echo esc_html( $ch['cardholder_status'] ); ?>
                </span>
            </div>

            <div style="margin-top: 10px; font-size: 14px; line-height: 1.6; color: #2c3338;">
                <p style="margin: 0;"><strong><?php esc_html_e( 'Address:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $ch['street_address'] ?: 'No address specified' ); ?></p>
                <p style="margin: 0;"><strong><?php esc_html_e( 'Type:', 'fsbhoa-ac' ); ?></strong> <?php echo esc_html( $ch['resident_type'] ?: ucfirst( $ch['cardholder_type'] ) ); ?></p>

                <?php if ( ! empty( $ch['email'] ) ) : ?>
                    <p style="margin: 0;">
                        <strong><?php esc_html_e( 'Email:', 'fsbhoa-ac' ); ?></strong> 
                        <a href="mailto:<?php echo esc_attr( $ch['email'] ); ?>" style="color: #2271b1; text-decoration: none;"><?php echo esc_html( $ch['email'] ); ?></a>
                    </p>
                <?php endif; ?>

                <?php if ( ! empty( $ch['phone'] ) ) : ?>
                    <p style="margin: 0;">
                        <strong><?php esc_html_e( 'Phone:', 'fsbhoa-ac' ); ?></strong> 
                        <?php echo esc_html( $ch['phone'] ); ?>
                        <span style="font-size: 12px; color: #646970;">(<?php echo esc_html( $ch['phone_type'] ?: 'Mobile' ); ?>)</span>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="padding: 0px;">

    <!-- Household Members -->
    <?php render_household_summary($ch); ?>

    <!-- Gate Access & Credentials -->
    <div style="margin-bottom: 0px;">
        <h3 style="margin: 0 0 0 0; font-size: 14px; font-weight: 600; color: #1d2327; border-bottom: 1px solid #eee; padding-bottom: 4px;">
            <?php esc_html_e( 'Gate Access & Credentials', 'fsbhoa-ac' ); ?>
        </h3>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
            <?php
            /**
             * HOOK: Allow hardware plugins to append their credentials
             * e.g. DoorKing Directory Code, Gate Code, etc.
             * e.g. uhppote for Photo ID rfid
             */
            do_action( 'fsbhoa_render_credential_fields',  $ch, 'summary' );
            ?>
        </div>
    </div>

    <!-- Household Vehicles -->
    <div style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #1d2327; border-bottom: 1px solid #eee; padding-bottom: 4px;">
            <?php esc_html_e( 'Household Vehicles', 'fsbhoa-ac' ); ?>
        </h3>
        <?php if ( empty( $vehicles ) ) : ?>
            <p style="margin: 0; font-size: 12px; color: #646970; font-style: italic;"><?php esc_html_e( 'No vehicles registered for this household.', 'fsbhoa-ac' ); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="width: 100%; border: 1px solid #c3c4c7; font-size: 12px; margin: 0;">
                <thead>
                    <tr style="background: #f0f0f1; text-align: left;">
                        <th style="padding: 6px 8px;"><?php esc_html_e( 'Type', 'fsbhoa-ac' ); ?></th>
                        <th style="padding: 6px 8px;"><?php esc_html_e( 'Make / Model', 'fsbhoa-ac' ); ?></th>
                        <th style="padding: 6px 8px;"><?php esc_html_e( 'Year', 'fsbhoa-ac' ); ?></th>
                        <th style="padding: 6px 8px;"><?php esc_html_e( 'Plate', 'fsbhoa-ac' ); ?></th>
                        <?php do_action( 'fsbhoa_vehicle_table_head', false ); ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $vehicles as $index => $v ) : 
                        $v_id = absint( $v['vehicle_id'] );
                    ?>
                        <tr>
                            <td style="padding: 6px 8px;"><?php echo esc_html( $v['vehicle_type'] ?: 'Automobile' ); ?></td>
                            <td style="padding: 6px 8px;"><?php echo esc_html( trim( ( $v['make'] ?? '' ) . ' ' . ( $v['model'] ?? '' ) ) ?: '&mdash;' ); ?></td>
                            <td style="padding: 6px 8px;"><?php echo esc_html( $v['year'] ?: '&mdash;' ); ?></td>
                            <td style="padding: 6px 8px;">
                                <strong><?php echo esc_html( $v['license_plate'] ?: '&mdash;' ); ?></strong>
                                <?php if ( ! empty( $v['license_plate'] ) ) : ?>
                                    <span style="color: #646970;">(<?php echo esc_html( $v['plate_state'] ?: 'CA' ); ?>)</span>
                                <?php endif; ?>
                            </td>
                            <?php do_action( 'fsbhoa_vehicle_table_row_columns', $v_id, $index, false ); ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>


    <!-- Permission Groups -->
    <div style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #1d2327; border-bottom: 1px solid #eee; padding-bottom: 4px;">
            <?php esc_html_e( 'Permission Groups', 'fsbhoa-ac' ); ?>
        </h3>
        <?php if ( empty( $groups ) ) : ?>
            <p style="margin: 0; font-size: 12px; color: #646970; font-style: italic;"><?php esc_html_e( 'No groups assigned.', 'fsbhoa-ac' ); ?></p>
        <?php else : ?>
            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                <?php foreach ( $groups as $g ) : ?>
                    <span style="background: #eaf1f8; color: #2271b1; border: 1px solid #c2dbf1; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                        <?php echo esc_html( $g['group_name'] ); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Notes Section at the Bottom -->
    <?php if ( ! empty( $ch['notes'] ) ) : ?>
        <div style="margin-bottom: 10px;">
            <h3 style="margin: 0 0 6px 0; font-size: 14px; font-weight: 600; color: #1d2327; border-bottom: 1px solid #eee; padding-bottom: 4px;"><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></h3>
            <div style="background: #f6f7f7; border: 1px solid #ddcdde; border-radius: 4px; padding: 8px 12px; font-size: 12px; color: #50575e; white-space: pre-wrap;">
                <?php echo esc_html( $ch['notes'] ); ?>
            </div>
        </div>
    <?php endif; ?>

    </div>

    <!-- Footer Control Strip -->
    <div style="padding: 12px 20px; background: #f6f7f7; border-top: 1px solid #dcdcde; display: flex; justify-content: space-between; align-items: center; border-radius: 0 0 8px 8px;">
        <div>
            <?php if ( ! $is_editable ) : ?>
                <span style="font-size: 12px; color: #d63638; font-style: italic;">
                    <?php esc_html_e( 'Record is archived/purged and cannot be directly edited.', 'fsbhoa-ac' ); ?>
                </span>
            <?php endif; ?>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <?php if ( $is_editable ) : ?>
                <a href="<?php echo $edit_url; ?>" target="_blank" class="button button-primary" style="display: inline-flex; align-items: center; gap: 4px;">
                    <span class="dashicons dashicons-edit" style="font-size: 16px; width: 16px; height: 16px; margin-top: 2px;"></span>
                    <?php esc_html_e( 'Open Full Edit Screen', 'fsbhoa-ac' ); ?>
                </a>
            <?php endif; ?>

            <button type="button" class="button button-secondary" onclick="(function(){ const m = document.getElementById('fsbhoa-cardholder-modal') || document.getElementById('fsbhoa-summary-modal-overlay'); if (m) { m.style.display = 'none'; } })(); return false;">
                <?php esc_html_e( 'Close', 'fsbhoa-ac' ); ?>
           </button>
        </div>
    </div>


  </div>
  <?php
  $output = ob_get_clean();

  if ( $echo ) {
    echo $output;
  } else {
    return $output;
  }

}

function render_household_summary( $ch ) {
    global $wpdb;

    $ch_status = $ch['cardholder_status'] ?? '';
    $ch_type   = $ch['cardholder_type']  ?? '';

    $is_archived = ( 'archived' === $ch_status || 'purged' === $ch_status );
    $is_landlord = ( 'landlord' === $ch_type );

    if ( $is_archived ) {
        $box_bg      = '#f0f0f1';
        $box_border    = '#dcdcde';
        $box_text      = '#50575e';
    } elseif ( $is_landlord ) {
        $box_bg      = '#fefce8';
        $box_border    = '#f5d76e';
        $box_text      = '#713f12';
    } else {
        $box_bg      = '#e6f4ea';
        $box_border    = '#ceead6';
        $box_text      = '#137333';
    }

    $household_id      = absint( $ch['household_id'] ?? 0 );
    $household_members = [];
    $primary_id        = 0;

    if ( $household_id > 0 ) {
        $primary_id = absint( $wpdb->get_var( $wpdb->prepare(
           "SELECT primary_cardholder_id FROM ac_households WHERE household_id = %d",
           $household_id
        ) ) );

        $household_members = $wpdb->get_results( $wpdb->prepare(
           "SELECT id, first_name, last_name, cardholder_status
            FROM ac_cardholders
            WHERE household_id = %d
            ORDER BY (id = %d) DESC, last_name ASC, first_name ASC",
           $household_id,
           $primary_id
        ), ARRAY_A );
    }
    ?>

    <div class="fsbhoa-summary-household-box" style="background: <?php echo esc_attr( $box_bg ); ?>; border: 1px solid <?php echo esc_attr( $box_border ); ?>; border-radius: 8px; padding: 12px 14px; margin-bottom: 15px;">
        <div style="font-weight: 600; font-size: 13px; margin-bottom: 6px; color: <?php echo esc_attr( $box_text ); ?>;">
            <?php esc_html_e( 'Household Members', 'fsbhoa-ac' ); ?>
        </div>

        <?php if ( ! empty( $household_members ) ) : ?>
            <ul style="margin: 0; padding: 0; list-style: none;">
            <?php foreach ( $household_members as $m ) :
                $m_id        = absint( $m['id'] ?? 0 );
                $is_self     = ( $m_id === absint( $ch['id'] ?? 0 ) );
                $is_primary  = ( $m_id === $primary_id );
                $member_name = trim( ( $m['first_name'] ?? '' ) . ' ' . ( $m['last_name'] ?? '' ) );
            ?>
                <li style="padding: 4px 0; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px dotted <?php echo esc_attr( $box_border ); ?>;">
                    <span style="<?php echo $is_self ? 'font-weight: bold;' : ''; ?>">
                        <?php echo esc_html( $member_name ); ?>
                        <?php if ( $is_self ) : ?>
                            <small style="color: #666;">(<?php esc_html_e( 'this cardholder', 'fsbhoa-ac' ); ?>)</small>
                        <?php endif; ?>
                    </span>

                    <div style="display: flex; gap: 6px; align-items: center;">
                        <?php if ( $is_primary ) : ?>
                            <span style="background: #0f5132; color: #ffffff; font-size: 10px; padding: 1px 6px; border-radius: 3px; text-transform: uppercase;">
                                <?php esc_html_e( 'Primary', 'fsbhoa-ac' ); ?>
                            </span>
                        <?php endif; ?>

                        <?php if ( ! $is_self && $m_id > 0 ) : ?>
                            <button type="button" class="button button-small" onclick="window.showCardholderSummaryModal(<?php echo $m_id; ?>); return false;" style="padding: 0 6px; font-size: 11px; line-height: 20px; height: 22px; cursor: pointer;">
                                <?php esc_html_e( 'View Summary', 'fsbhoa-ac' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <em style="color: #666;"><?php esc_html_e( 'No household members found.', 'fsbhoa-ac' ); ?></em>
        <?php endif; ?>
    </div>
    <?php
}




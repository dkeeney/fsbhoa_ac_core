<?php
if ( ! defined( 'WPINC' ) ) { die; }

function fsbhoa_render_vehicles_section( $form_data ) {
	global $wpdb;

	$cardholder_id = isset( $form_data['id'] ) ? absint( $form_data['id'] ) : 0;
	$household_id  = isset( $form_data['household_id'] ) ? absint( $form_data['household_id'] ) : 0;
	$vehicles      = [];

	if ( $household_id > 0 ) {
		$vehicles = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM ac_vehicles WHERE household_id = %d ORDER BY vehicle_id ASC",
			$household_id
		), ARRAY_A );
	}
	?>
	<div id="fsbhoa-vehicles-section-container" style="margin-top: 20px; background: #fff; border: 1px solid #ccd0d4; padding: 15px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">

		<div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 12px;">
			<h3 style="margin: 0; font-size: 14px; font-weight: 600;">Vehicles & Windshield Tags</h3>
			<button type="button" id="fsbhoa-add-vehicle-row-btn" style="background: none; border: none; color: #2271b1; text-decoration: underline; cursor: pointer; padding: 0; font-size: 13px; font-weight: 600;">
				+ Add Vehicle
			</button>
		</div>

		<table class="widefat striped" style="border: 1px solid #c3c4c7; width: 100%; margin: 0;" id="fsbhoa-vehicles-table">
			<thead>
				<tr>
					<th style="padding: 6px; width: 12%;">Type</th>
					<th style="padding: 6px; width: 22%;">Make / Model</th>
					<th style="padding: 6px; width: 5%;">Year</th>
					<th style="padding: 6px; width: 15%;">Plate / State</th>
					<?php do_action( 'fsbhoa_vehicle_table_head' ); ?>
					<th style="padding: 6px; width: 8%; text-align: center;">Action</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $vehicles ) ) : ?>
					<tr id="fsbhoa-no-vehicles-row">
						<td colspan="6" style="text-align: center; color: #666; padding: 15px;">No vehicles currently registered to this resident.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $vehicles as $index => $v ) : $v_id = absint( $v['vehicle_id'] ); ?>
						<tr>
							<td style="padding: 4px;">
								<input type="hidden" name="vehicle_rows[<?php echo $index; ?>][vehicle_id]" value="<?php echo $v_id; ?>" />
								<select name="vehicle_rows[<?php echo $index; ?>][vehicle_type]" style="width:100%;padding:2px;font-size:12px;">
									<?php foreach ( [ 'Automobile', 'Truck', 'SUV', 'Golf Cart', 'RV', 'Motorcycle' ] as $t ) : ?>
										<option value="<?php echo $t; ?>" <?php selected( $v['vehicle_type'], $t ); ?>><?php echo $t; ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td style="padding: 4px;"><input type="text" name="vehicle_rows[<?php echo $index; ?>][make_model]" value="<?php echo esc_attr( trim( ( $v['make'] ?? '' ) . ' ' . ( $v['model'] ?? '' ) ) ); ?>" style="width:100%;padding:2px;font-size:12px;" /></td>
							<td style="padding: 4px;"><input type="text" name="vehicle_rows[<?php echo $index; ?>][year]" value="<?php echo esc_attr( $v['year'] ?? '' ); ?>" maxlength="4" style="width:100%;padding:2px;font-size:12px;" /></td>
							<td style="padding: 4px;"><div style="display:flex;gap:3px;"><input type="text" name="vehicle_rows[<?php echo $index; ?>][license_plate]" value="<?php echo esc_attr( $v['license_plate'] ?? '' ); ?>" style="flex:2;padding:2px;font-size:12px;text-transform:uppercase;" /><input type="text" name="vehicle_rows[<?php echo $index; ?>][plate_state]" value="<?php echo esc_attr( $v['plate_state'] ?? 'CA' ); ?>" maxlength="5" style="flex:1;padding:2px;font-size:12px;text-transform:uppercase;" /></div></td>
							<?php do_action( 'fsbhoa_vehicle_table_row_columns', $v_id, $index ); ?>
							<td style="padding: 4px; text-align: center; vertical-align: middle;">
								<label style="color:#d63638;font-size:11px;cursor:pointer;"><input type="checkbox" name="vehicle_rows[<?php echo $index; ?>][delete]" value="1" /> Rm</label>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}


/**
 * Validates Vehicle data from a form submission.
 *
 * @param array $post_data The raw $_POST data.
 * @return array An array with 'errors' and 'data' keys.
 */
function fsbhoa_validate_vehicles_data( $post_data ) {
    $errors = array();
    $validated_rows = array();

    if ( empty($post_data['vehicle_rows']) || !is_array($post_data['vehicle_rows']) ) {
        return array('errors' => $errors, 'data' => array('vehicle_rows' => []));
    }

    foreach ( $post_data['vehicle_rows'] as $index => $row ) {
        $v_id = isset($row['vehicle_id']) ? absint($row['vehicle_id']) : 0;

        // Handle deletion flags
        if ( !empty($row['delete']) ) {
            if ( $v_id > 0 ) {
                $validated_rows[] = array( 'vehicle_id' => $v_id, 'delete' => true );
            }
            continue;
        }

        $make_model    = isset($row['make_model']) ? sanitize_text_field(wp_unslash($row['make_model'])) : '';
        $license_plate = isset($row['license_plate']) ? sanitize_text_field(wp_unslash($row['license_plate'])) : '';
        $plate_state   = isset($row['plate_state']) ? sanitize_text_field(wp_unslash($row['plate_state'])) : 'CA';
        $year          = isset($row['year']) ? sanitize_text_field(wp_unslash($row['year'])) : '';
        $type          = isset($row['vehicle_type']) ? sanitize_text_field(wp_unslash($row['vehicle_type'])) : 'Automobile';

        // SAFETY RULE: If this is an EXISTING record (v_id > 0), NEVER treat it as an empty row,
        // even if its make/model/plate are blank. Preserving existing records overrides everything.
        $is_existing_record = ($v_id > 0);

        if ( ! $is_existing_record ) {
            // It's a brand new row. Check if core fields are empty.
            $is_empty = empty($make_model) && empty($license_plate);

            // Allow hardware extensions to declare a brand new row is NOT empty (e.g. if it has an RFID tag)
            $is_empty = apply_filters('fsbhoa_is_vehicle_row_empty', $is_empty, $row);

            if ( $is_empty ) {
                continue; // Skip truly empty, unsubmitted new rows
            }
        }

        // Allow hardware extensions to validate their own fields and throw errors
        $errors = apply_filters('fsbhoa_validate_vehicle_row', $errors, $row);

        if ( !empty($year) && !preg_match('/^\d{4}$/', $year) ) {
            $errors[] = "Vehicle year must be a 4-digit number.";
        }

        $parts = explode(' ', trim($make_model), 2);

        //  Cast empty plates to NULL so they don't violate the database's unique index
        $final_plate = empty(trim($license_plate)) ? null : strtoupper(trim($license_plate));

        $validated_rows[] = array(
            'vehicle_id'    => $v_id,
            'vehicle_type'  => $type,
            'make'          => $parts[0] ?? '',
            'model'         => $parts[1] ?? '',
            'year'          => !empty($year) ? absint($year) : null,
            'license_plate' => $final_plate,
            'plate_state'   => strtoupper($plate_state),
            'raw_row'       => $row
        );
    }

    return array('errors' => $errors, 'data' => array('vehicle_rows' => $validated_rows));
}



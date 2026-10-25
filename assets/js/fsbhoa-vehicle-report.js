// The Vehicle Log report ([fsbhoa_vehicle_report]): ac_vehicle_log from /reports/vehicle-log,
// each row drawn with what the live monitor's vehicle card shows.
jQuery(document).ready(function($) {
    const nonceParam = '&_wpnonce=' + encodeURIComponent(fsbhoa_reports_vars.rest_nonce);
    const authPrefixes = { DK_WINDSHIELD: 'Tag ', DK_ENTRY_CODE: 'Gate Code #', DK_DIR_CODE: 'DIR: ' };

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function isTrue(value) {
        return value == 1 || value === true;
    }

    window.openVehiclePhoto = function(url) {
        $('#fsbhoa-vehicle-report-modal-img').attr('src', url);
        $('#fsbhoa-vehicle-report-modal').css('display', 'flex');
    };

    // One photo, or a blank place for one: purged (older than the Vehicle Photo Retention) or never taken
    function photo(row, type, fullType, caption) {
        const id = parseInt(row.vehicle_log_id, 10);
        const flag = { lpr: row.has_lpr_img, context: row.has_context_img, overview: row.has_overview_img, note: row.has_note_img };
        const box = 'width: 80px; height: 50px; border-radius: 4px; border: 1px solid #cbd5e1;';
        let img = '<div style="' + box + ' border-style: dashed; background: transparent;"></div>';
        if (isTrue(flag[type])) {
            const thumbUrl = '/wp-json/fsbhoa/v1/vehicle-image/' + id + '?type=' + type + nonceParam;
            const fullUrl = '/wp-json/fsbhoa/v1/vehicle-image/' + id + '?type=' + fullType + nonceParam;
            img = '<img loading="lazy" src="' + thumbUrl + '" alt="' + caption + '" title="Click to enlarge" ' +
                  'onclick="openVehiclePhoto(\'' + fullUrl + '\')" ' +
                  'style="' + box + ' object-fit: cover; background: #e2e8f0; cursor: pointer;">';
        }
        return '<div style="text-align: center;">' + img +
               '<div style="font-size: 9px; color: #64748b; margin-top: 2px;">' + caption + '</div></div>';
    }

    // The card's photos: the plate crop (opening the scene photo), the gate photo, and the
    // note's photo when the row has a note
    function renderPhotos(data, type, row) {
        let plateType = 'lpr', plateFull = 'context';
        if (!isTrue(row.has_lpr_img) && isTrue(row.has_context_img)) plateType = 'context';
        if (!isTrue(row.has_context_img)) plateFull = plateType;
        let html = row.alert_key ? '' : photo(row, plateType, plateFull, 'Plate');
        html += photo(row, 'overview', 'overview', 'Gate');
        if (row.event_note || isTrue(row.has_note_img)) html += photo(row, 'note', 'note', 'Note');
        html = '<div style="display: flex; gap: 6px;">' + html + '</div>';
        // What the plate camera made of a vehicle it read no plate for, under the photos
        if (row.vehicle_desc) {
            html += '<div style="font-size: 11px; color: #475569; margin-top: 3px; max-width: 260px;" title="The plate camera\'s description">' + esc(row.vehicle_desc) + '</div>';
        }
        return html;
    }

    function renderPlate(data, type, row) {
        if (row.alert_key) {
            const active = isTrue(row.alert_active);
            const notice = isTrue(row.alert_notice);
            const color = notice ? (active ? '#92400e' : '#334155') : (active ? '#991b1b' : '#166534');
            return '<span style="font-weight: bold; color: ' + color + ';">' + (active ? (notice ? '&#9432; ' : '&#9888; ') : '&#10003; ') +
                   esc(row.alert_text || row.alert_key) + '</span>';
        }
        // As on the card: no text with plate photos is a vehicle without a readable plate;
        // no photos either is a car counted by another camera that the plate camera never read
        const plate = row.lpr_plate_string ||
            (isTrue(row.has_lpr_img) || isTrue(row.has_context_img) ? 'NO PLATE' : 'NO PLATE READ');
        return '<span style="font-weight: bold; font-family: monospace; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; border: 1px solid #cbd5e1; color: #0f172a;">' + esc(plate) + '</span>';
    }

    function renderLane(data, type, row) {
        const gate = row.gate_identifier || 'Vehicle Gate';
        const exit = row.direction === 'exit' && !/exit/i.test(gate)
            ? ' <span style="font-size: 10px; font-weight: bold; color: #475569; background: #e2e8f0; padding: 1px 6px; border-radius: 4px;">EXIT</span>'
            : '';
        return esc(gate) + exit;
    }

    function renderCredential(data, type, row) {
        if (row.auth_id) return esc((authPrefixes[row.auth_type] || 'Auth: ') + row.auth_id);
        return isTrue(row.is_circumvention) ? '<strong style="color: #991b1b;">NO CREDENTIAL</strong>' : '';
    }

    function renderCardholder(data, type, row) {
        const others = (parseInt(row.credential_matches, 10) || 0) - 1;
        const othersNote = others > 0
            ? ' <span style="font-size: 11px; color: #64748b;" title="This credential belongs to more than one person">(+' + others + ' other' + (others > 1 ? 's' : '') + ')</span>'
            : '';
        const id = parseInt(row.cardholder_id, 10);
        if (id > 0) {
            return '<a href="/cardholder/?action=edit_cardholder&cardholder_id=' + id + '" style="font-weight: bold; text-decoration: underline; color: #2271b1;">' +
                   esc(row.cardholder_name || 'View Cardholder') + '</a>' + othersNote;
        }
        return row.cardholder_name ? esc(row.cardholder_name) + othersNote : '';
    }

    function renderNote(data, type, row) {
        const parts = [];
        if (row.event_note) {
            parts.push('<span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">' + esc(row.event_note) + '</span>');
        }
        if (isTrue(row.is_circumvention)) {
            parts.push('<span style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">&#9888; Gate Tripped Without Auth</span>');
        }
        if (row.alert_ack_at) {
            parts.push('<span style="font-size: 12px; color: #64748b;">Acknowledged by ' + esc(row.alert_ack_by || 'staff') + ' at ' + esc(row.alert_ack_at) + '</span>');
        }
        return parts.join('<br>');
    }

    const table = $('#fsbhoa-vehicle-log-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/wp-json/fsbhoa/v1/reports/vehicle-log',
            type: 'POST',
            headers: { 'X-WP-Nonce': fsbhoa_reports_vars.rest_nonce },
            data: function(d) {
                d.start_date = $('#vehicle_start_date').val();
                d.end_date = $('#vehicle_end_date').val();
                d.lane = $('#vehicle_lane').val();
                d.kind = $('#vehicle_kind').val();
            }
        },
        columns: [
            { data: null, defaultContent: '', className: 'select-checkbox', orderable: false },
            { data: 'event_timestamp', render: data => esc(String(data || '').replace(/\.\d+$/, '')) },
            { data: null, orderable: false, className: 'vehicle-photos-column', render: renderPhotos },
            { data: 'lpr_plate_string', render: renderPlate },
            { data: 'gate_identifier', render: renderLane },
            { data: 'auth_id', render: renderCredential },
            { data: 'cardholder_name', render: renderCardholder },
            { data: 'event_note', orderable: false, render: renderNote }
        ],
        order: [[1, 'desc']],
        searching: true,
        dom: 'rtip',
        pageLength: 100,
        lengthMenu: [100, 200, 500, 1000],
        select: { style: 'os', selector: 'td:first-child' }
    });

    let searchTimer = null;
    $('#vehicle-live-search').on('keyup', function() {
        const value = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => table.search(value).draw(), 300);
    });
    $('#vehicle_start_date, #vehicle_end_date, #vehicle_lane, #vehicle_kind').on('change', function() { table.draw(); });
    $('#vehicle-page-length').on('change', function() { table.page.len($(this).val()).draw(); });
    $('#vehicle-show-photos').on('change', function() { table.column(2).visible($(this).is(':checked')); });
    $('#vehicle-clear-filters').on('click', function() {
        $('#vehicle_start_date, #vehicle_end_date, #vehicle-live-search').val('');
        $('#vehicle_lane, #vehicle_kind').val('');
        $('#vehicle-show-photos').prop('checked', true);
        table.column(2).visible(true);
        table.search('').draw();
    });

    // Export the selected rows, or with none selected every row the filters match
    $('#vehicle-export-button').on('click', function(e) {
        e.preventDefault();
        const selected = table.rows({ selected: true }).data().toArray();
        if (selected.length > 0) {
            const form = $('<form>', { method: 'POST', action: '/wp-admin/admin-post.php' }).appendTo('body');
            form.append($('<input>', { type: 'hidden', name: 'action', value: 'fsbhoa_export_selected_vehicle_logs' }));
            form.append($('<input>', { type: 'hidden', name: 'nonce', value: fsbhoa_reports_vars.export_nonce }));
            form.append($('<input>', { type: 'hidden', name: 'log_ids', value: selected.map(r => r.vehicle_log_id).join(',') }));
            form.submit();
            return;
        }
        const params = new URLSearchParams({ action: 'fsbhoa_export_vehicle_log', nonce: fsbhoa_reports_vars.export_nonce });
        const filters = {
            start_date: $('#vehicle_start_date').val(), end_date: $('#vehicle_end_date').val(),
            lane: $('#vehicle_lane').val(), kind: $('#vehicle_kind').val(), search: $('#vehicle-live-search').val()
        };
        Object.keys(filters).forEach(k => { if (filters[k]) params.set(k, filters[k]); });
        window.location.href = '/wp-admin/admin-post.php?' + params.toString();
    });
});

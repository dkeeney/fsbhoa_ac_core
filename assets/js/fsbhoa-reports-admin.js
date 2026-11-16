jQuery(document).ready(function($) {

    const accessLogTable = $('#fsbhoa-access-log-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            "url": "/wp-json/fsbhoa/v1/reports/access-log",
            "type": "POST",
            "headers": { "X-WP-Nonce": fsbhoa_reports_vars.rest_nonce },
            "data": function (d) {
                d.start_date = $('#start_date').val();
                d.end_date = $('#end_date').val();
                d.gate_id = $('#gate_id').val();
                d.show_photo = $('#show-photo').is(':checked');
            }
        },
        "columns": [
            { "data": null, "defaultContent": "", "className": "select-checkbox", "orderable": false },
            { "data": "event_timestamp", "className": "datetime-column" },
            {
              "data": "photo",
              "orderable": false,
              "className": "photo-column",
              "render": function(data, type, row) {
                  const fallbackSvg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23aaaaaa'%3E%3Cpath d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/%3E%3C/svg%3E";

                  if (data) {
                      return '<img src="data:image/jpeg;base64,' + data + '" alt="photo" style="width: 48px; height: 48px; object-fit: cover; border-radius: 4px;">';
                  }

                  // Return the SVG silhouette with a light grey background
                  return '<img src="' + fallbackSvg + '" alt="no photo" style="width: 48px; height: 48px; object-fit: cover; border-radius: 4px; padding: 4px; background-color: #f0f0f1;">';
              }
            },
            { "data": "cardholder", 
              "render": function(data, type, row) {
                    // Only render a link if there is a valid cardholder ID attached to the event
                    if (row.cardholder_id) {
                        return '<a href="/cardholder/?action=edit_cardholder&cardholder_id=' + row.cardholder_id + '" style="font-weight:bold; text-decoration:underline; color:#2271b1;">' + data + '</a>';
                    }
                    return data;
              }
            },
            { "data": "resident_type", "className": "type-column" },
            { "data": "property", "className": "property-column" },
            { "data": "gate_name" },
            { "data": "access_granted", "orderable": false },
            { "data": "event_description", "className": "description-column", "orderable": false }
        ],
        "columnDefs": [ { "targets": 2, "visible": false } ],
        "order": [[ 1, "desc" ]],
        "searching": true,
        "dom": 'rtip',
        "pageLength": 100,
        "lengthMenu": [100, 200, 500, 1000],
        "select": { "style": 'os', "selector": 'td:first-child' }
    });

    // --- Custom Control Handlers ---
    $('#fsbhoa-live-search').on('keyup', function() { accessLogTable.search($(this).val()).draw(); });
    $('#start_date, #end_date, #gate_id').on('change', function() { accessLogTable.draw(); });
    $('#fsbhoa-page-length').on('change', function() { accessLogTable.page.len($(this).val()).draw(); });
    $('#show-photo').on('change', function() {
        accessLogTable.column(2).visible($(this).is(':checked')).draw();
    });
    $('#fsbhoa-clear-filters').on('click', function() {
        $('#start_date, #end_date, #fsbhoa-live-search').val('');
        $('#gate_id').val('');
        $('#show-photo').prop('checked', false);
        accessLogTable.column(2).visible(false);
        accessLogTable.search('').draw();
    });

    // --- NEW: Smart Export Button Handler ---
    $('#fsbhoa-export-button').on('click', function(e) {
        e.preventDefault();

        const selectedData = accessLogTable.rows({ selected: true }).data().toArray();

        // --- Path 1: Export Selected Rows ---
        if (selectedData.length > 0) {
            const logIds = selectedData.map(row => row.log_id);

            const form = $('<form>', {
                'method': 'POST',
                'action': '/wp-admin/admin-post.php'
            }).appendTo('body');

            form.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'fsbhoa_export_selected_logs' }));
            form.append($('<input>', { 'type': 'hidden', 'name': 'nonce', 'value': fsbhoa_reports_vars.export_nonce }));
            form.append($('<input>', { 'type': 'hidden', 'name': 'log_ids', 'value': logIds.join(',') }));

            form.submit();

        // --- Path 2: Export All Filtered Rows ---
        } else {
            const startDate = $('#start_date').val();
            const endDate = $('#end_date').val();
            const gateId = $('#gate_id').val();
            const search = $('#fsbhoa-live-search').val();
            const nonce = fsbhoa_reports_vars.export_nonce;

            let url = `/wp-admin/admin-post.php?action=fsbhoa_export_access_log&nonce=${nonce}`;
            if (startDate) url += `&start_date=${encodeURIComponent(startDate)}`;
            if (endDate) url += `&end_date=${encodeURIComponent(endDate)}`;
            if (gateId) url += `&gate_id=${encodeURIComponent(gateId)}`;
            if (search) url += `&search=${encodeURIComponent(search)}`;
            
            window.location.href = url;
        }
    });
});

jQuery(document).ready(function($) {
    'use strict';

    // The red banner (Fsbhoa_Push::render_banner). Each hardware plugin reports its own push
    // (fsbhoa_push_targets); fsbhoa_get_sync_status returns the overall status:
    // in_progress, error, pending or complete, and one line per target.
    const syncBanner = $('#fsbhoa-sync-banner');
    const syncButton = $('#fsbhoa-sync-banner-button');
    const syncMessageSpan = $('#fsbhoa-sync-banner-message');
    const POLL_MS = 2000; // a DoorKing push waits minutes for RAM, so don't poll faster
    let pollingInterval = null;

    // Check if a sync is already in progress on page load
    if (fsbhoa_sync_vars.is_sync_in_progress) {
        syncButton.prop('disabled', true).text('Pushing...');
        setTimeout(pollSyncStatus, 500);
        pollingInterval = setInterval(pollSyncStatus, POLL_MS);
    }

    function stopPolling() {
        clearInterval(pollingInterval);
        pollingInterval = null;
    }

    function startSync() {
        if (syncButton.prop('disabled')) {
            return;
        }

        syncButton.prop('disabled', true).text('Starting...');
        syncMessageSpan.text('Push scheduled. Please wait...');
        syncBanner.css('background-color', '');

        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_sync_all_controllers',
            nonce: fsbhoa_sync_vars.nonce
        })
        .done(function(response) {
            if (response.success) {
                setTimeout(pollSyncStatus, 2000); // give WP-Cron a moment to start
                if (!pollingInterval) {
                    pollingInterval = setInterval(pollSyncStatus, POLL_MS);
                }
            } else {
                syncMessageSpan.text('Error: Could not start the push.');
                syncButton.prop('disabled', false).text('Push Changes Now');
            }
        })
        .fail(function() {
            syncMessageSpan.text('Error: Failed to communicate with server.');
            syncButton.prop('disabled', false).text('Push Changes Now');
        });
    }

    function pollSyncStatus() {
        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_get_sync_status',
            nonce: fsbhoa_sync_vars.nonce
        })
        .done(function(response) {
            if (!response.success) {
                stopPolling();
                syncMessageSpan.text('Error: Could not get the push status.');
                syncButton.prop('disabled', false).text('Push Changes Now');
                return;
            }
            const data = response.data;

            if (data.status === 'complete') {
                // Everything pushed
                stopPolling();
                syncMessageSpan.html('<strong>Push complete!</strong> ').append(document.createTextNode(data.message || ''));
                syncBanner.css('background-color', '#46b450'); // Green
                setTimeout(function() {
                    syncBanner.slideUp();
                }, 4000);

            } else if (data.status === 'in_progress') {
                syncMessageSpan.text(data.message);
                syncButton.prop('disabled', true).text('Pushing...');

            } else if (data.status === 'error') {
                stopPolling();
                syncMessageSpan.html('<strong>Push failed.</strong> ').append(document.createTextNode(data.message || ''));
                syncBanner.css('background-color', '#dc3232'); // Red
                syncButton.prop('disabled', false).text('Push Changes Now');

            } else { // pending: changes are waiting (made during the push, or nothing ran)
                stopPolling();
                syncMessageSpan.text('There are pending changes that need to be pushed to the hardware. ' + (data.message || ''));
                syncButton.prop('disabled', false).text('Push Changes Now');
            }
        })
        .fail(function() {
            stopPolling();
            syncMessageSpan.text('Error: Failed to communicate with server.');
            syncButton.prop('disabled', false).text('Push Changes Now');
        });
    }

    // Main button click handler
    $('body').on('click', '#fsbhoa-sync-banner-button', function(e) {
        e.preventDefault();
        startSync();
    });
});

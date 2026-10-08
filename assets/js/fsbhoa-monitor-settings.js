/**
 * Handles interactivity for the Monitor Settings page.
 * - Media uploader for the map image.
 * - Draggable gate markers for position setting.
 * - Single AJAX call to save all settings.
 */
jQuery(document).ready(function($) {

    'use strict';

    // --- Part 1: Media Uploader ---
    const mapUploaderButton = $('#fsbhoa_monitor_map_url-button');
    const mapHiddenInput = $('#fsbhoa_monitor_map_url');
    const mapEditorImage = $('#fsbhoa-map-editor-bg');

    mapUploaderButton.on('click', function(e) {
        e.preventDefault();
        const frame = wp.media({
            title: 'Select or Upload a Map Image',
            button: { text: 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            mapHiddenInput.val(attachment.url);
            mapEditorImage.attr('src', attachment.url);
        });
        frame.open();
    });

    // --- Part 2: Gate Position Editor ---
    const mapContainer = $('#fsbhoa-map-editor-container');
    const legendList = $('#fsbhoa-gate-legend ol');
    const GATES_API_URL = '/wp-json/fsbhoa/v1/monitor/gates';
    let gatePositions = {};

    function initializeGateEditor() {
        if (!mapContainer.length) return;

        $.ajax({ url: GATES_API_URL, dataType: 'json', headers: { 'X-WP-Nonce': fsbhoa_monitor_settings_vars.rest_nonce } })
            .done(function(gates) {
                mapContainer.find('.gate-marker').remove();
                legendList.empty();

                if (!gates || gates.length === 0) {
                    legendList.append('<li>No gates configured. Please add gates under Hardware Management.</li>');
                    return;
                }

                gates.forEach(function(gate, index) {
                    const markerNumber = index + 1;
                    // Initialize positions from DB
                    gatePositions[gate.door_record_id] = {
                        x: gate.map_x,
                        y: gate.map_y
                    };

                    const marker = $('<div class="gate-marker"></div>')
                        .attr('id', 'gate-marker-' + gate.door_record_id)
                        .attr('data-id', gate.door_record_id)
                        .attr('title', gate.friendly_name)
                        .css({
                            left: gate.map_x + '%',
                            top: gate.map_y + '%'
                        })
                        .text(markerNumber);
                    
                    mapContainer.append(marker);

                    const listItem = $('<li></li>').html(`<b>${markerNumber}:</b> ${gate.friendly_name}`);
                    legendList.append(listItem);
                });
                makeMarkersDraggable();
            })
            .fail(function() {
                legendList.empty().append('<li>Could not load gate data. Check server logs.</li>');
            });
    }

    function makeMarkersDraggable() {
        let activeMarker = null;
        mapContainer.on('mousedown', '.gate-marker', function(e) {
            e.preventDefault();
            activeMarker = $(this);
        });

        $(document).on('mousemove', function(e) {
            if (!activeMarker) return;
            e.preventDefault();
            const containerRect = mapContainer[0].getBoundingClientRect();
            let x = e.clientX - containerRect.left;
            let y = e.clientY - containerRect.top;
            
            // Calculate percentage position, constrained between 0 and 100
            let xPercent = Math.max(0, Math.min(100, (x / containerRect.width) * 100));
            let yPercent = Math.max(0, Math.min(100, (y / containerRect.height) * 100));
            
            activeMarker.css({
                left: xPercent + '%',
                top: yPercent + '%'
            });

            // Update local store of positions
            const gateId = activeMarker.data('id');
            gatePositions[gateId] = {
                x: xPercent,
                y: yPercent
            };
        });

        $(document).on('mouseup', function() {
            activeMarker = null;
        });
    }

    // --- Part 3: Unified Save Handler ---
    const saveButton = $('#fsbhoa-save-monitor-settings-button');
    const feedbackSpan = $('#fsbhoa-save-feedback');

    saveButton.on('click', function() {
        feedbackSpan.text('Saving...').css('color', 'blue').show();
        saveButton.prop('disabled', true);

        const dataToSend = {
            action: 'fsbhoa_save_monitor_settings',
            nonce: fsbhoa_monitor_settings_vars.nonce,
            map_url: mapHiddenInput.val(),
            port: $('#fsbhoa_ac_monitor_port').val(),
            status_group_id: $('#fsbhoa_monitor_status_group_id').val(),
            photo_limit: $('#fsbhoa_ac_monitor_photo_limit').val(),
            status_group_id: $('#fsbhoa_monitor_status_group_id').val(),
            gates: Object.keys(gatePositions).map(id => ({
                id: id,
                x: Math.round(gatePositions[id].x),
                y: Math.round(gatePositions[id].y)
            }))
        };

        $.post(fsbhoa_monitor_settings_vars.ajax_url, dataToSend)
            .done(function(response) {
                if (response.success) {
                    feedbackSpan.text('Success! Reloading...').css('color', 'green');
                    setTimeout(function() {
                        location.reload();
                    }, 1000); // Wait 1 second before reloading
                } else {
                    feedbackSpan.text('Error: ' + response.data).css('color', 'red');
                    saveButton.prop('disabled', false);
                }
            })
            .fail(function() {
                feedbackSpan.text('Request failed. Check network or server logs.').css('color', 'red');
                saveButton.prop('disabled', false);
            });
    });

    // Initialize the editor
    initializeGateEditor();
});


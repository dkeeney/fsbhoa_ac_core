jQuery(function($) {

    const App = {
        isInitialized: false,
        vars: {},

        init: function() {
            if (this.isInitialized) { return; }
            this.isInitialized = true;

            this.cacheSelectors();

            if (this.vars.cardholderTable && this.vars.cardholderTable.length) {
                this.initDataTable();
                this.bindTableControlEvents();
            }

            if (this.vars.cardholderForm && this.vars.cardholderForm.length) {
                this.initFormLibraries();
                this.bindFormEvents();
                this.updateMainPhotoDisplay(this.vars.initialMainPhotoSrc);
                this.handleResidentTypeChange();

                // AUTO-LOAD PANEL & PRE-FILL
                const urlParams = new URLSearchParams(window.location.search);
                const passedPropertyId = urlParams.get('property_id');
                const passedAddress = urlParams.get('property_address');

                if (passedPropertyId) {
                    // We arrived here from a redirect! Pre-fill the hidden ID and visible text.
                    this.vars.propertyIdHiddenInput.val(passedPropertyId);
                    if (passedAddress) {
                        $('#fsbhoa_property_search_input').val(passedAddress);
                    }
                    this.fetchPropertyOccupants(passedPropertyId);
                } else {
                    // Normal edit screen auto-load
                    const initialPropertyId = this.vars.propertyIdHiddenInput.val();
                    if (initialPropertyId && initialPropertyId !== '0' && initialPropertyId !== '') {
                        this.fetchPropertyOccupants(initialPropertyId);
                    }
                }
            }
        },

        cacheSelectors: function() {
            this.vars = {
                // Page-level containers
                cardholderForm: $('#fsbhoa-cardholder-form'),
                cardholderTable: $('#fsbhoa-cardholder-table'),

                // Custom table controls
                customLengthMenu: $('#fsbhoa-custom-length-menu'),
                customSearchInput: $('#fsbhoa-custom-search-input'),

                // Property Section
                propertySearchInput: $('#fsbhoa_property_search_input'),
                propertyIdHiddenInput: $('#fsbhoa_property_id_hidden'),
                selectedPropertyDisplay: $('#fsbhoa_selected_property_display'),
                propertyListCache: [],
                householdPanel: $('#fsbhoa_household_panel'),

                // RFID Section
                rfidInput: $('#rfid_id'),
                statusUiToggleCheckbox: $('#fsbhoa_card_status_ui_toggle'),
                statusToggleContainer: $('#fsbhoa_card_status_toggle_container'),
                submittedStatusHidden: $('#fsbhoa_submitted_card_status'),
                statusDisplaySpan: $('#fsbhoa_card_status_display'),
                toggleLabelSpan: $('#fsbhoa_card_status_toggle_ui_label'),
                issueDateDisplay: $('#fsbhoa_card_issue_date_display'),
                issueDateHidden: $('#fsbhoa_submitted_card_issue_date'),
                contractorExpiryContainer: $('#fsbhoa_expiry_date_wrapper_contractor'),
                residentTypeInput: $('#resident_type'),

                // Photo Section
                mainPhotoPreviewImg: $('#fsbhoa_photo_preview_main_img'),
                noPhotoMessage: $('#fsbhoa_no_photo_message'),
                cropPhotoButton: $('#fsbhoa-crop-photo-btn'),
                photoBase64Input: $('#fsbhoa_photo_base64'),
                fileUploadSection: $('#fsbhoa_file_upload_section'),
                fileUploadInput: $('#cardholder_photo_file_input'),
                startWebcamButton: $('#fsbhoa_start_webcam_button'),
                webcamContainer: $('#fsbhoa_webcam_container'),
                videoElement: document.getElementById('fsbhoa_webcam_video'),
                webcamActiveControls: $('#fsbhoa_webcam_active_controls'),
                stopWebcamButton: $('#fsbhoa_stop_webcam_button'),
                capturePhotoButton: $('#fsbhoa_capture_photo_button'),
                canvasElement: document.getElementById('fsbhoa_webcam_canvas'),
                removePhotoButton: $('#fsbhoa_remove_photo_button'),
                exportPhotoButton: $('#fsbhoa-export-photo-btn'),
                printIdButton: $('#print-id-card-button'),
                firstNameInput: $('#first_name'),
                lastNameInput: $('#last_name'),
                webcamErrorMessage: $('#fsbhoa_webcam_error_message'),
                stream: null,
                initialMainPhotoSrc: ($('#fsbhoa_photo_preview_main_img').attr('src') && $('#fsbhoa_photo_preview_main_img').attr('src') !== '#') ? $('#fsbhoa_photo_preview_main_img').attr('src') : null,
            };
        },

        initDataTable: function() {
            if (!this.vars.cardholderTable.length) { return; }

            this.dataTableInstance = this.vars.cardholderTable.DataTable({
                "dom": 'tip',
                "pageLength": 100,
                "stateSave": true,
                "columnDefs": [ {
                    "targets": 0,
                    "orderable": false,
                    "className": 'select-checkbox',
                    "data": null,
                    "defaultContent": ''
                } ],
                "select": {
                    "style": 'multi+shift',
                    "selector": 'td:first-child'
                },
                "order": [[ 2, 'asc' ]]
            });

            const state = this.dataTableInstance.state.loaded();
            if (state) {
                if (state.search && state.search.search) {
                    $('#fsbhoa-cardholder-search-input').val(state.search.search);
                }
                if (state.length) {
                    $('#fsbhoa-custom-length-menu').val(state.length);
                }
            }

            const urlParams = new URLSearchParams(window.location.search);
            const highlightId = urlParams.get('highlight');

            if (highlightId && this.dataTableInstance) {
                const dt = this.dataTableInstance;
                const rowNode = dt.row(`[data-cardholder-id="${highlightId}"]`).node();

                if (rowNode) {
                    const rowDtIndex = dt.row(rowNode).index();
                    const displayIndex = dt.rows({order: 'current', search: 'applied'}).indexes().indexOf(rowDtIndex);

                    if (displayIndex >= 0) {
                        const pageLength = dt.page.len();
                        if (pageLength > 0) {
                            const pageNumber = Math.floor(displayIndex / pageLength);
                            dt.page(pageNumber).draw('page');
                        }

                        setTimeout(() => {
                            const $row = $(rowNode);
                            $row.addClass('fsbhoa-highlight-target');
                            $('html, body').animate({
                                scrollTop: $row.offset().top - 150
                            }, 800);
                        }, 200);
                    }
                }

                const cleanUrl = window.location.href.replace(/([&?])highlight=\d+/, '');
                window.history.replaceState({}, document.title, cleanUrl);
            }
        },

        bindTableControlEvents: function() {
            if (!this.dataTableInstance) { return; }

            $('#fsbhoa-cardholder-search-input').on('keyup', (e) => {
                this.dataTableInstance.search(e.target.value).draw();
            });

            $('#fsbhoa-custom-length-menu').on('change', (e) => {
                this.dataTableInstance.page.len(e.target.value).draw();
            });

            $('#cb-select-all').on('change', (e) => {
                const isChecked = $(e.target).prop('checked');
                if (isChecked) {
                    this.dataTableInstance.rows({search:'applied'}).select();
                } else {
                    this.dataTableInstance.rows({search:'applied'}).deselect();
                }
            });

            this.dataTableInstance.on('select deselect', function (e, dt, type, indexes) {
                if (type === 'row') {
                    const nodes = dt.rows(indexes).nodes().toArray();
                    const isSelected = e.type === 'select';
                    $(nodes).find('.cardholder-cb').prop('checked', isSelected);
                }
            });

            $('#fsbhoa-bulk-action-form').on('submit', (e) => {
                const action = $('#bulk-action-selector').val();
                const selectedNodes = this.dataTableInstance.rows({ selected: true }).nodes();

                if (action === '-1') {
                    e.preventDefault();
                    return;
                }

                if (selectedNodes.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one cardholder.');
                    return;
                }

                if (action === 'archive') {
                    if (!confirm('You are about to archive ' + selectedNodes.length + ' cardholder(s). This cannot be undone from this screen. Are you sure?')) {
                        e.preventDefault();
                        return;
                    }
                    e.preventDefault(); 

                    const adminPostUrl = fsbhoa_ajax_settings.ajax_url.replace('admin-ajax.php', 'admin-post.php');
                    const archiveForm = $('<form>', { 'method': 'POST', 'action': adminPostUrl });

                    archiveForm.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'fsbhoa_bulk_cardholder_action' }));
                    archiveForm.append($('<input>', { 'type': 'hidden', 'name': '_wpnonce', 'value': $('#fsbhoa-bulk-action-form input[name="_wpnonce"]').val() }));
                    archiveForm.append($('<input>', { 'type': 'hidden', 'name': 'bulk_action', 'value': 'archive' }));

                    for (let i = 0; i < selectedNodes.length; i++) {
                        const id = $(selectedNodes[i]).data('cardholder-id');
                        if (id) {
                            archiveForm.append($('<input>', { 'type': 'hidden', 'name': 'cardholder_ids[]', 'value': id }));
                        }
                    }

                    $('body').append(archiveForm);
                    archiveForm.submit();
                    archiveForm.remove();

                    $('#bulk-action-selector').val('-1');
                }
                else if (action === 'print') {
                    e.preventDefault();

                    const order = this.dataTableInstance.order()[0]; 
                    const orderColumnIndex = order[0];
                    const orderDirection = order[1];

                    const printNonce = $('#fsbhoa-cardholder-management-wrap').data('print-nonce') || fsbhoa_ajax_settings.print_report_nonce;
                    const adminPostUrl = fsbhoa_ajax_settings.ajax_url.replace('admin-ajax.php', 'admin-post.php');

                    const printForm = $('<form>', {
                        'method': 'POST',
                        'action': adminPostUrl,
                        'target': '_blank'
                    });

                    printForm.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'fsbhoa_print_report' }));
                    printForm.append($('<input>', { 'type': 'hidden', 'name': '_wpnonce', 'value': printNonce }));
                    printForm.append($('<input>', { 'type': 'hidden', 'name': 'orderby_col', 'value': orderColumnIndex }));
                    printForm.append($('<input>', { 'type': 'hidden', 'name': 'order_dir', 'value': orderDirection }));

                    for (let i = 0; i < selectedNodes.length; i++) {
                        const id = $(selectedNodes[i]).data('cardholder-id');
                        if (id) {
                            printForm.append($('<input>', { 'type': 'hidden', 'name': 'cardholder_ids[]', 'value': id }));
                        }
                    }

                    $('body').append(printForm);
                    printForm.submit();
                    printForm.remove();

                    $('#bulk-action-selector').val('-1');
                }
                else if (action === 'export') {
                    e.preventDefault(); 

                    const exportNonce = $('#fsbhoa-cardholder-management-wrap').data('export-nonce') || fsbhoa_ajax_settings.export_nonce;
                    const adminPostUrl = fsbhoa_ajax_settings.ajax_url.replace('admin-ajax.php', 'admin-post.php');
                    const exportForm = $('<form>', { 'method': 'POST', 'action': adminPostUrl });

                    exportForm.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'fsbhoa_export_selected' }));
                    exportForm.append($('<input>', { 'type': 'hidden', 'name': '_wpnonce', 'value': exportNonce }));

                    for (let i = 0; i < selectedNodes.length; i++) {
                        const id = $(selectedNodes[i]).data('cardholder-id');
                        if (id) {
                            exportForm.append($('<input>', { 'type': 'hidden', 'name': 'cardholder[]', 'value': id }));
                        }
                    }

                    $('body').append(exportForm);
                    exportForm.submit();
                    exportForm.remove();

                    $('#bulk-action-selector').val('-1');

                } else if (action === 'purge') {   // only for vendor list
                    e.preventDefault();

                    if (!confirm('You are about to PURGE ' + selectedNodes.length + ' vendor(s). Their access will be revoked immediately, but their log history will be preserved. Proceed?')) {
                        return;
                    }

                    const adminPostUrl = (typeof fsbhoa_ajax_settings !== 'undefined' && fsbhoa_ajax_settings.ajax_url)
                        ? fsbhoa_ajax_settings.ajax_url.replace('admin-ajax.php', 'admin-post.php')
                        : '/wp-admin/admin-post.php';

                    const purgeForm = $('<form>', { 'method': 'POST', 'action': adminPostUrl });

                    purgeForm.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'fsbhoa_bulk_vendor_action' }));
                    purgeForm.append($('<input>', { 'type': 'hidden', 'name': '_wpnonce', 'value': $('#fsbhoa-bulk-action-form input[name="_wpnonce"]').val() }));
                    purgeForm.append($('<input>', { 'type': 'hidden', 'name': 'bulk_action', 'value': 'purge' }));
                
                    for (let i = 0; i < selectedNodes.length; i++) {
                        const id = $(selectedNodes[i]).data('cardholder-id');
                        if (id) {
                            purgeForm.append($('<input>', { 'type': 'hidden', 'name': 'cardholder_ids[]', 'value': id }));
                        }
                    }

                    $('body').append(purgeForm);
                    purgeForm.submit();
                    purgeForm.remove();
                
                    $('#bulk-action-selector').val('-1');
                
                // NOTE: the delete is not hooked up.  Kept for future use.
                } else if (action === 'delete') {   // only for vendor list
                    e.preventDefault();

                    if (!confirm('You are about to permanently DELETE ' + selectedNodes.length + ' vendor(s) and their associated credentials. This cannot be undone. Are you sure?')) {
                        return;
                    }

                    const adminPostUrl = (typeof fsbhoa_ajax_settings !== 'undefined' && fsbhoa_ajax_settings.ajax_url)
                        ? fsbhoa_ajax_settings.ajax_url.replace('admin-ajax.php', 'admin-post.php')
                        : '/wp-admin/admin-post.php';

                    const deleteForm = $('<form>', { 'method': 'POST', 'action': adminPostUrl });

                    deleteForm.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'fsbhoa_bulk_vendor_action' }));
                    deleteForm.append($('<input>', { 'type': 'hidden', 'name': '_wpnonce', 'value': $('#fsbhoa-bulk-action-form input[name="_wpnonce"]').val() }));
                    deleteForm.append($('<input>', { 'type': 'hidden', 'name': 'bulk_action', 'value': 'delete' }));

                    for (let i = 0; i < selectedNodes.length; i++) {
                        const id = $(selectedNodes[i]).data('cardholder-id');
                        if (id) {
                            deleteForm.append($('<input>', { 'type': 'hidden', 'name': 'cardholder_ids[]', 'value': id }));
                        }
                    }

                    $('body').append(deleteForm);
                    deleteForm.submit();
                    deleteForm.remove();

                    $('#bulk-action-selector').val('-1');
                }
            });


        },

        initFormLibraries: function() {
            if (typeof FSBHOA_Croppie !== 'undefined') {
                FSBHOA_Croppie.init((croppedImageDataURL) => {
                    this.updateMainPhotoDisplay(croppedImageDataURL);
                    if (this.vars.photoBase64Input.length) {
                        const base64Data = croppedImageDataURL.split(',')[1] || "";
                        this.vars.photoBase64Input.val(base64Data);
                    }
                });
            }
            this.initPropertyAutocomplete();
        },

        initPropertyAutocomplete: function() {
            if (this.vars.propertySearchInput.length) {
                const self = this;
                self.vars.propertySearchInput.autocomplete({
                    source: (request, response) => {
                        $.ajax({
                            url: fsbhoa_ajax_settings.ajax_url,
                            dataType: "json",
                            data: {
                                action: 'fsbhoa_search_properties',
                                term: request.term,
                                security: fsbhoa_ajax_settings.property_search_nonce
                            },
                            success: (data) => {
                                if (data.success) {
                                    self.vars.propertyListCache = data.data;
                                    response(data.data.length ? data.data : [{ label: 'No results found', value: '' }]);
                                } else {
                                    self.vars.propertyListCache = [];
                                    response([]);
                                }
                            }
                        });
                    },
                    minLength: 1,
                    select: (event, ui) => {
                        event.preventDefault();
                        if (ui.item && ui.item.id) {
                            self.vars.propertySearchInput.val(ui.item.label);
                            self.vars.propertyIdHiddenInput.val(ui.item.id);
                            self.fetchPropertyOccupants(ui.item.id);
                        }
                        return false;
                    }
                });
                
                self.vars.propertySearchInput.on('change', function() {
                    const enteredText = $(this).val().trim();
                    let foundMatch = false;

                    if (enteredText === '') {
						self.vars.propertyIdHiddenInput.val('');
						self.vars.householdPanel.slideUp().empty();
						$('#fsbhoa-vehicles-table tbody').html(`
							<tr id="fsbhoa-no-vehicles-row">
								<td colspan="6" style="text-align: center; color: #666; padding: 15px;">No vehicles currently registered to this resident.</td>
							</tr>
						`);
						return;
					}

                    for (let i = 0; i < self.vars.propertyListCache.length; i++) {
                        if (self.vars.propertyListCache[i].label.toLowerCase() === enteredText.toLowerCase()) {
                            self.vars.propertyIdHiddenInput.val(self.vars.propertyListCache[i].id);
                            $(this).val(self.vars.propertyListCache[i].label);
                            self.fetchPropertyOccupants(self.vars.propertyListCache[i].id);
                            foundMatch = true;
                            break;
                        }
                    }

                    if (!foundMatch) {
                        self.vars.propertyIdHiddenInput.val('');
                        self.vars.householdPanel.slideUp().empty();
                        self.vars.householdPanel.data('loaded-property', '');
                    }
                });
            }
        },

        fetchPropertyOccupants: function(propertyId) {
            const self = this;
            if (!propertyId) {
                self.vars.householdPanel.slideUp().empty();
                return;
            }
            if (self.vars.householdPanel.data('loaded-property') == propertyId) {
                return;
            }
            self.vars.householdPanel.data('loaded-property', propertyId);
            self.vars.householdPanel.html('<p><em>Checking current occupants...</em></p>').slideDown();

            const cardholderId = parseInt($('input[name="cardholder_id"]').val(), 10) || 0;
            const resType = $('#resident_type').val() || '';

            $.ajax({
                url: fsbhoa_ajax_settings.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'fsbhoa_check_property_occupants',
                    property_id: propertyId,
                    cardholder_id: cardholderId,
                    resident_type: resType,
                    security: fsbhoa_ajax_settings.property_search_nonce
                },
                success: function(response) {
                    if (response.success) {
                        const occupants = response.data.occupants || response.data;
                        self.renderHouseholdPanel(occupants);

                        // Only replace vehicles if we are ADDING a new cardholder picking an address.
                        // For edit mode (cardholderId > 0), the page or change-type AJAX handles the table.
                        if (response.data.vehicles_html && cardholderId === 0) {
                            $('#fsbhoa-vehicles-section-container').replaceWith(response.data.vehicles_html);
                        }
                    } else {
                        self.vars.householdPanel.html('<p style="color:red;">Error checking occupants: ' + response.data + '</p>');
                    }
                },
                error: function() {
                    self.vars.householdPanel.html('<p style="color:red;">Network error checking occupants.</p>');
                }
            });
        },

        renderHouseholdPanel(occupants) {
            // Normalize data (just in case)
            if (typeof occupants === 'string') { try { occupants = JSON.parse(occupants); } catch(e) {} }
            if (occupants && occupants.data !== undefined) occupants = occupants.data;

            if (!occupants || !Array.isArray(occupants)) {
                occupants = [];
            }

            let urlParams = new URLSearchParams(window.location.search);
            let actionParam = urlParams.get('action');
            let currentEditId = parseInt(urlParams.get('id')) || parseInt(urlParams.get('cardholder_id')) || 0;
            let propertyId = this.vars.propertyIdHiddenInput.val();
            let addressText = $('#fsbhoa_property_search_input').val();
            let isAddMode = (actionParam === 'add' || currentEditId === 0);

            // ---  SHORT-CIRCUIT FOR NON-RESIDENTS ---
            let currentResType = $('#resident_type').val();
            let standaloneTypes = ['Contractor', 'Staff', 'Other', 'Emergency', 'Delivery'];

            // If the currently edited person is a non-resident, hide the household UI entirely.
            if (standaloneTypes.includes(currentResType)) {
                this.vars.householdPanel.html('<p style="color: #666; font-style: italic; margin: 4px 0;">Household management is not applicable for this cardholder type. Vehicles and credentials are managed independently.</p>').show();
                return; // Stop rendering the rest of the panel!
            }

            // Filter out all non-residents so they NEVER pollute normal household lists
            occupants = occupants.filter(occ => !standaloneTypes.includes(occ.resident_type));
            // ----------------------------------------------

            // --- FIND OR CREATE THE PLACEHOLDER PRESERVING ITS STATE ---
            let existingPlaceholder = occupants.find(occ => occ.is_placeholder);

            if (isAddMode && propertyId) {
                let firstNameVal = $('#first_name').length && $('#first_name').val().trim() !== '' ? $('#first_name').val().trim() : 'New';
                let lastNameVal = $('#last_name').length && $('#last_name').val().trim() !== '' ? $('#last_name').val().trim() : 'Cardholder';
                let selectedType = $('#resident_type').length && $('#resident_type').val() !== '' ? $('#resident_type').val() : 'Resident Owner';

                if (!existingPlaceholder) {
                    occupants.push({
                        id: 0,
                        first_name: firstNameVal,
                        last_name: lastNameVal,
                        cardholder_status: 'active',
                        resident_type: selectedType,
                        is_placeholder: true
                    });
                } else {
                    existingPlaceholder.first_name = firstNameVal;
                    existingPlaceholder.last_name = lastNameVal;
                    // Keep whatever type was assigned via dropdown
                }
            } else {
                occupants = occupants.filter(occ => !occ.is_placeholder);
            }

            // Cache the array for the dropdown/type switcher
            this.vars.cachedOccupants = occupants;

            // 1. Split the occupants into our distinct groups
            let liveOccupants = occupants.filter(occ => occ.cardholder_status !== 'archived');
            let archivedOccupants = occupants.filter(occ => occ.cardholder_status === 'archived');

            let liveLandlords = liveOccupants.filter(occ => occ.resident_type === 'Landlord');
            let liveResidents = liveOccupants.filter(occ => occ.resident_type !== 'Landlord');
            let html = '';

            // --- HELPER: Derive Household Name from unique last names ---
            let deriveHouseholdName = (list) => {
                let validNames = list.filter(occ => !occ.is_placeholder && occ.last_name && occ.last_name.trim() !== '')
                                     .map(occ => occ.last_name.trim());
                if (validNames.length === 0) return '';
                let uniqueNames = [...new Set(validNames)];
                return ' — <span style="font-weight:normal; font-size:12px; color:#555;">' + uniqueNames.join(' / ') + ' Household</span>';
            };

            // Helper to build the "+ Add" URL
            let getAddUrl = () => {
                let addParams = new URLSearchParams(window.location.search);
                addParams.set('action', 'add');
                addParams.delete('id');
                addParams.delete('cardholder_id');
                if (propertyId) addParams.set('property_id', propertyId);
                if (addressText) addParams.set('property_address', addressText);
                return window.location.pathname + '?' + addParams.toString();
            };

            // Helper to generate list items
            let buildListItems = (list) => {
                if (list.length === 0) return '<p style="margin: 3px 0; color: #666; font-style: italic; font-size: 13px;">None</p>';
                let listHtml = '<ul style="margin: 0; padding: 0; list-style: none;">';

                let isCurrentManual = $('#manual_override').is(':checked');

                list.forEach(occ => {
                    let isCurrentEditing = (parseInt(occ.id, 10) === currentEditId) || occ.is_placeholder;
                    let occIsManual = (occ.origin === 'manual');
                    let showMergeButton = !isCurrentEditing && currentEditId > 0 && (
                        (isCurrentManual && !occIsManual) || (!isCurrentManual && occIsManual)
                    );
                    let primaryId = isCurrentManual ? occ.id : currentEditId;
                    let duplicateId = isCurrentManual ? currentEditId : occ.id;

                    listHtml += '<li style="padding: 4px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">';
                    listHtml += '<strong>' + occ.first_name + ' ' + occ.last_name + '</strong>';

                    listHtml += '<div style="display: flex; gap: 12px; align-items: center; font-size: 13px;">';

                    if (isCurrentEditing) {
                        listHtml += '<span style="color: #666; font-style: italic;">(Currently Editing)</span>';
                    } else {
                        let editParams = new URLSearchParams(window.location.search);
                        editParams.set('action', 'edit_cardholder');
                        editParams.set('cardholder_id', occ.id);
                        listHtml += '<a href="' + window.location.pathname + '?' + editParams.toString() + '" style="text-decoration: none; font-weight: 600; color: #137333;">Edit</a>';

                        if (showMergeButton) {
                            listHtml += '<a href="#" class="fsbhoa-merge-cardholder-btn" data-primary="' + primaryId + '" data-duplicate="' + duplicateId + '" data-name="' + occ.first_name + ' ' + occ.last_name + '" style="text-decoration: none; font-weight: 600; color: #7200ca;">Merge</a>';
                        }
                    }

                    if (!occ.is_placeholder) {
                        listHtml += '<a href="#" class="fsbhoa-instant-archive-btn" data-id="' + occ.id + '" style="text-decoration: none; font-weight: 600; color: #d63638;">Archive</a>';
                    }

                    listHtml += '</div></li>';
                });
                listHtml += '</ul>';
                return listHtml;
            };

            // Helper to generate a debug button for a specific household ID
            let getDebugBtn = (list) => {
                let validOcc = list.find(occ => occ.household_id && parseInt(occ.household_id, 10) > 0);
                let hhId = validOcc ? validOcc.household_id : 0;
                return `
                    <button type="button" class="fsbhoa-copy-hh-debug-btn" data-household-id="${hhId}" title="Copy this household diagnostic table to clipboard" style="background: none; border: none; cursor: pointer; padding: 2px 5px; vertical-align: middle; color: #666;">
                        <span class="dashicons dashicons-clipboard" style="font-size: 16px; width: 16px; height: 16px;"></span>
                    </button>
                `;
            };

            // --- 1. RENDER LANDLORDS FIRST (BLUE BOX) ---
            if (liveLandlords.length > 0) {
                html += '<div style="background: #eaf1f8; border: 1px solid #2271b1; padding: 6px 10px; border-radius: 4px; margin-bottom: 8px;">';
                html += '<div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #2271b1; padding-bottom: 3px; margin-bottom: 6px;">';
                html += '<p style="margin:0; font-weight: 600; color: #2271b1;">Landlord' + deriveHouseholdName(liveLandlords) + getDebugBtn(liveLandlords) + '</p>';
                html += '</div>';
                html += buildListItems(liveLandlords);
                html += '</div>';
            }

            // --- 2. RENDER LIVE RESIDENTS SECOND (GREEN BOX) ---
            html += '<div style="background: #e6f4ea; border: 1px solid #137333; padding: 6px 10px; border-radius: 4px; margin-bottom: 8px;">';
            html += '<div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #137333; padding-bottom: 3px; margin-bottom: 6px;">';
            html += '<p style="margin:0; font-weight: 600; color: #137333;">Current Residents' + deriveHouseholdName(liveResidents) + getDebugBtn(liveResidents) + '</p>';
            html += '<a href="' + getAddUrl() + '" style="text-decoration: none; font-size: 13px; font-weight: 600; color: #137333;">+ Add Resident</a>';
            html += '</div>';
            html += buildListItems(liveResidents);
            html += '</div>';

            // --- 3. RENDER ARCHIVED LAST (GRAY BOX) ---
            if (archivedOccupants.length > 0) {
                html += '<div style="background: #f0f0f1; border: 1px solid #c3c4c7; padding: 6px 10px; border-radius: 4px;">';
                html += '<p style="margin:0 0 6px 0; font-weight: 600; color: #50575e; border-bottom: 1px solid #c3c4c7; padding-bottom: 3px;">Archived History' + deriveHouseholdName(archivedOccupants) + '</p>';
                html += '<ul style="margin: 0; padding: 0; list-style: none;">';
                archivedOccupants.forEach(occ => {
                    html += '<li style="padding: 4px 0; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; color: #666;">';
                    html += '<span>' + occ.first_name + ' ' + occ.last_name + (occ.resident_type === 'Landlord' ? ' <em style="font-size:12px;">(Landlord)</em>' : '') + '</span>';

                    html += '<div style="display: flex; gap: 12px; align-items: center; font-size: 13px;">';
                    if (parseInt(occ.id, 10) === currentEditId) {
                        html += '<span style="font-style: italic;">(Currently Editing)</span>';
                    } else {
                        html += '<a href="#" class="fsbhoa-restore-btn" data-id="' + occ.id + '" style="text-decoration: none; font-weight: 600; color: #2271b1;">Restore</a>';
                        html += '<a href="#" class="fsbhoa-instant-purge-btn" data-id="' + occ.id + '" data-name="' + occ.first_name + ' ' + occ.last_name + '" style="text-decoration: none; font-weight: 600; color: #a10a0a;">Purge</a>';
                    }
                    html += '</div></li>';
                });
                html += '</ul></div>';
            }

            this.vars.householdPanel.html(html);
        },

        handleResidentTypeChange: function() {
            if (!this.vars.residentTypeInput) return;

            // Store the initial/current type so the user can cancel a change and revert cleanly
            this.vars.residentTypeInput.data('current-type', this.vars.residentTypeInput.val());
            const selectedType = this.vars.residentTypeInput.val();

            // 1. Existing Contractor Logic
            if (selectedType === 'Contractor') {
                this.vars.contractorExpiryContainer.show();
            } else {
                this.vars.contractorExpiryContainer.hide();
            }

            // 2. Dynamic Household Panel Movement (Supports both Edit and Add-mode Placeholders)
            let urlParams = new URLSearchParams(window.location.search);
            let actionParam = urlParams.get('action');
            let currentEditId = parseInt(urlParams.get('id')) || parseInt(urlParams.get('cardholder_id')) || 0;
            let isAddMode = (actionParam === 'add' || currentEditId === 0);

            if (this.vars.cachedOccupants) {
                let occupant = this.vars.cachedOccupants.find(occ => 
                    (currentEditId > 0 && parseInt(occ.id, 10) === currentEditId) || 
                    (isAddMode && occ.is_placeholder)
                );

                if (occupant && occupant.resident_type !== selectedType) {
                    let oldType = occupant.resident_type;

                    // Update their type in the cache and instantly re-render the panel
                    occupant.resident_type = selectedType;
                    this.renderHouseholdPanel(this.vars.cachedOccupants);

                    // 3. Wipe Vehicles if crossing the Landlord boundary
                    if (selectedType === 'Landlord' || oldType === 'Landlord') {
                        this.vars.cardholderForm.find('input[name*="vehicle"], input[name*="make"], input[name*="model"], input[name*="plate"], input[name*="year"], input[name*="color"]').val('');

                        alert('Notice: Changing a resident to or from "Landlord" separates them into a distinct household. Their vehicle entries on this form have been cleared.');
                    }
                }
            }
        },

        handleAddVehicleRow: function(e) {
            e.preventDefault();
            const tbody = $('#fsbhoa-vehicles-table tbody');
            const noVehiclesRow = $('#fsbhoa-no-vehicles-row');

            if (noVehiclesRow.length) {
                noVehiclesRow.remove();
            }

            const index = tbody.find('tr').length;

            const newRowHtml = `
            <tr>
                <td style="padding: 4px;">
                    <!-- Hidden input safely inside the TD -->
                    <input type="hidden" name="vehicle_rows[${index}][vehicle_id]" value="0" />

                    <select name="vehicle_rows[${index}][vehicle_type]" style="width:100%;padding:2px;font-size:12px;">
                        <option value="Automobile">Automobile</option>
                        <option value="Truck">Truck</option>
                        <option value="SUV">SUV</option>
                        <option value="Golf Cart">Golf Cart</option>
                        <option value="RV">RV</option>
                        <option value="Motorcycle">Motorcycle</option>
                    </select>
                </td>
                <td style="padding: 4px;">
                    <input type="text" name="vehicle_rows[${index}][make_model]" value="" placeholder="Make/Model" style="width:100%;padding:2px;font-size:12px;" />
                </td>
                <td style="padding: 4px;">
                    <input type="text" name="vehicle_rows[${index}][year]" value="" maxlength="4" placeholder="YYYY" style="width:100%;padding:2px;font-size:12px;" />
                </td>
                <td style="padding: 4px;">
                    <div style="display:flex;gap:3px;">
                        <input type="text" name="vehicle_rows[${index}][license_plate]" value="" placeholder="Plate" style="flex:2;padding:2px;font-size:12px;text-transform:uppercase;" />
                        <input type="text" name="vehicle_rows[${index}][plate_state]" value="CA" maxlength="5" style="flex:1;padding:2px;font-size:12px;text-transform:uppercase;" />
                    </div>
                </td>
                <td style="padding: 4px;">
                    <input type="text" name="vehicle_rows[${index}][dk_windshield]" value="" maxlength="5" placeholder="e.g. 12345" pattern="\\d*" style="width: 100%; padding: 2px; font-size: 12px;" />
                </td>
                <td style="padding: 4px; text-align: center; vertical-align: middle;">
                    <span style="color:#72777c;font-size:11px;">New</span>
                </td>
            </tr>
        `;

            tbody.append(newRowHtml);
        },


        bindFormEvents: function() {
            const formContainer = this.vars.cardholderForm;
            if (!formContainer.length) { return; }

            formContainer.on('click', '#fsbhoa-crop-photo-btn', () => this.handleCropButtonClick());
            formContainer.on('click', '#fsbhoa_card_status_ui_toggle', () => this.updateStatusDisplayFromCheckbox());
            formContainer.on('click', '#fsbhoa_start_webcam_button', () => this.startWebcam());
            formContainer.on('click', '#fsbhoa_stop_webcam_button', () => this.stopWebcam());
            formContainer.on('click', '#fsbhoa_capture_photo_button', () => this.captureWebcamPhoto());
            formContainer.on('change', '#cardholder_photo_file_input', (e) => this.handleFileSelect(e));
            formContainer.on('input', '#rfid_id', () => this.handleRfidInputChange());
            formContainer.on('click', '#fsbhoa_remove_photo_button', () => this.handleRemovePhotoButtonClick());
            formContainer.on('click', '#fsbhoa-export-photo-btn', () => this.handleExportPhotoClick());
            formContainer.on('click', '#print-id-card-button', (e) => { e.preventDefault(); this.handlePrintIdClick(); });
            formContainer.on('click', '#fsbhoa-add-vehicle-row-btn', (e) => this.handleAddVehicleRow(e));
            // Dynamically reload household panel when resident type changes to catch standalone types
            // Handle resident type changes and trigger household re-assignment for existing members
            formContainer.on('change', '#resident_type', (e) => {
                const $select = $(e.currentTarget);
                const newType = $select.val();
                const cardholderId = parseInt($('input[name="cardholder_id"]').val(), 10) || 0;
                const propId = this.vars.propertyIdHiddenInput.val();

                // If creating a brand new cardholder (not yet saved), just refresh the preview panel
                if (!cardholderId) {
                    if (propId) {
                        this.vars.householdPanel.data('loaded-property', '');
                        this.fetchPropertyOccupants(propId);
                    }
                    return;
                }

                if (!confirm("Changing resident type may reassign this person's household and vehicles. Proceed?")) {
                    // Revert to previous value if canceled
                    $select.val($select.data('current-type') || 'Resident Owner');
                    return;
                }

                $select.prop('disabled', true);

                $.ajax({
                    url: fsbhoa_ajax_settings.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'fsbhoa_ajax_change_resident_type',
                        cardholder_id: cardholderId,
                        new_type: newType,
                        security: fsbhoa_ajax_settings.property_search_nonce
                    },
                    success: (response) => {
                        $select.prop('disabled', false);
                        if (response.success) {
                            $select.data('current-type', newType);

                            // Update vehicle table with the new household's vehicles
                            if (response.data.vehicles_html) {
                                $('#fsbhoa-vehicles-section-container').replaceWith(response.data.vehicles_html);
                            }

                            // Force-refresh the household panel to show them in the Landlord box
                            if (propId) {
                                this.vars.householdPanel.data('loaded-property', '');
                                this.fetchPropertyOccupants(propId);
                            }
                        } else {
                            alert('Failed to update resident type: ' + response.data);
                            $select.val($select.data('current-type') || 'Resident Owner');
                        }
                    },
                    error: () => {
                        $select.prop('disabled', false);
                        alert('Network error while updating resident type.');
                        $select.val($select.data('current-type') || 'Resident Owner');
                    }
                });
            });
            this.vars.cardholderForm.on('submit', (e) => this.handleFormValidation(e));


            // ----------------------------------------------------------------------
            // 1. Instant Save: (Edit Profile / Add Member)
            // ----------------------------------------------------------------------
            this.vars.householdPanel.on('click', 'a', (e) => {
                e.preventDefault();
                let targetUrl = $(e.currentTarget).attr('href');
                let clickedLink = $(e.currentTarget);
                let originalText = clickedLink.text();

                // BULLETPROOF BYPASS: Is this a completely blank "Add New" form?
                let actionVal = $('input[name="action"]').val();
                let isNewRecord = (actionVal === 'fsbhoa_do_add_cardholder');
                let first = $('#first_name').length ? $('#first_name').val().trim() : '';
                let last = $('#last_name').length ? $('#last_name').val().trim() : '';

                if (isNewRecord && first === '' && last === '') {
                    window.location.href = targetUrl; // Skip auto-save, just go!
                    return;
                }

                clickedLink.text('Saving changes...').css('opacity', '0.6');
                let formData = new FormData(this.vars.cardholderForm[0]);
                formData.append('action', 'fsbhoa_ajax_save_cardholder');
                formData.append('security', fsbhoa_ajax_settings.property_search_nonce);

                $.ajax({
                    url: fsbhoa_ajax_settings.ajax_url, type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
                    success: (response) => {
                        if (response.success) { window.location.href = targetUrl; }
                        else {
                            clickedLink.text(originalText).css('opacity', '1');
                            alert('Could not switch context because of validation errors:\n\n• ' + response.data.errors.join('\n• '));
                        }
                    },
                    error: () => { clickedLink.text(originalText).css('opacity', '1'); alert('Network error auto-saving.'); }
                });
            });

            // ----------------------------------------------------------------------
            // 2. Instant Archive Button Handler
            // ----------------------------------------------------------------------
            this.vars.householdPanel.on('click', '.fsbhoa-instant-archive-btn', (e) => {
                e.preventDefault();
                if (!confirm('Are you sure you want to instantly archive this resident and disable their credentials? This cannot be undone.')) { return; }

                let btn = $(e.currentTarget);
                let listItem = btn.closest('li');
                let cardholderId = btn.data('id');
                let propertyId = this.vars.propertyIdHiddenInput.val();

                // BULLETPROOF BYPASS CHECK
                let actionVal = $('input[name="action"]').val();
                let isNewRecord = (actionVal === 'fsbhoa_do_add_cardholder');
                let first = $('#first_name').length ? $('#first_name').val().trim() : '';
                let last = $('#last_name').length ? $('#last_name').val().trim() : '';

                let executeArchive = () => {
                    btn.text('Archiving...');
                    $.ajax({
                        url: fsbhoa_ajax_settings.ajax_url, type: 'POST',
                        data: { action: 'fsbhoa_ajax_archive_cardholder', cardholder_id: cardholderId, security: fsbhoa_ajax_settings.property_search_nonce },
                        success: (archiveResponse) => {
                            if (archiveResponse.success) {
                                let urlParams = new URLSearchParams(window.location.search);
                                let currentEditId = parseInt(urlParams.get('id')) || parseInt(urlParams.get('cardholder_id')) || 0;

                                if (cardholderId == currentEditId) {
                                    urlParams.set('action', 'add');
                                    urlParams.delete('id');
                                    urlParams.delete('cardholder_id');
                                    urlParams.set('property_id', propertyId);
                                    let addressText = $('#fsbhoa_property_search_input').val();
                                    if (addressText) urlParams.set('property_address', addressText);
                                    window.location.href = window.location.pathname + '?' + urlParams.toString();
                                } else {
                                    listItem.css({'text-decoration': 'line-through', 'color': '#999'}).fadeOut(400, () => {
                                        this.vars.householdPanel.data('loaded-property', '');
                                        this.fetchPropertyOccupants(propertyId);
                                    });
                                }
                            } else {
                                alert('Failed to archive: ' + archiveResponse.data);
                                btn.prop('disabled', false).text('Archive / Move Out');
                            }
                        },
                        error: () => { alert('A network error occurred.'); btn.prop('disabled', false).text('Archive / Move Out'); }
                    });
                };

                if (isNewRecord && first === '' && last === '') {
                    btn.prop('disabled', true);
                    executeArchive(); // Skip save!
                } else {
                    btn.prop('disabled', true).text('Saving current...');
                    let formData = new FormData(this.vars.cardholderForm[0]);
                    formData.append('action', 'fsbhoa_ajax_save_cardholder');
                    formData.append('security', fsbhoa_ajax_settings.property_search_nonce);

                    $.ajax({
                        url: fsbhoa_ajax_settings.ajax_url, type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
                        success: (saveResponse) => {
                            if (saveResponse.success) { executeArchive(); }
                            else {
                                btn.prop('disabled', false).text('Archive / Move Out');
                                alert('Could not archive because the current record has validation errors:\n\n• ' + saveResponse.data.errors.join('\n• '));
                            }
                        },
                        error: () => { btn.prop('disabled', false).text('Archive / Move Out'); alert('Network error auto-saving.'); }
                    });
                }
            });

            // ----------------------------------------------------------------------
            // 3. Instant Restore Button Handler
            // ----------------------------------------------------------------------
            this.vars.householdPanel.on('click', '.fsbhoa-restore-btn', (e) => {
                e.preventDefault();
                if (!confirm('Are you sure you want to restore this resident and reactivate their access?')) { return; }

                let btn = $(e.currentTarget);
                let cardholderId = btn.data('id');

                // BULLETPROOF BYPASS CHECK
                let actionVal = $('input[name="action"]').val();
                let isNewRecord = (actionVal === 'fsbhoa_do_add_cardholder');
                let first = $('#first_name').length ? $('#first_name').val().trim() : '';
                let last = $('#last_name').length ? $('#last_name').val().trim() : '';

                let executeRestore = () => {
                    btn.text('Restoring...');
                    $.ajax({
                        url: fsbhoa_ajax_settings.ajax_url, type: 'POST',
                        data: { action: 'fsbhoa_ajax_restore_cardholder', cardholder_id: cardholderId, security: fsbhoa_ajax_settings.property_search_nonce },
                        success: (restoreResponse) => {
                            if (restoreResponse.success) {
                                let urlParams = new URLSearchParams(window.location.search);
                                urlParams.set('action', 'edit_cardholder');
                                urlParams.set('cardholder_id', cardholderId);
                                window.location.href = window.location.pathname + '?' + urlParams.toString();
                            } else {
                                alert('Failed to restore: ' + restoreResponse.data);
                                btn.prop('disabled', false).text('Restore (Move back in)');
                            }
                        },
                        error: () => { alert('A network error occurred.'); btn.prop('disabled', false).text('Restore (Move back in)'); }
                    });
                };

                if (isNewRecord && first === '' && last === '') {
                    btn.prop('disabled', true);
                    executeRestore(); // Skip auto-save on blank form
                } else {
                    btn.prop('disabled', true).text('Saving current... ');
                    let formData = new FormData(this.vars.cardholderForm[0]);
                    formData.append('action', 'fsbhoa_ajax_save_cardholder');
                    formData.append('security', fsbhoa_ajax_settings.property_search_nonce);

                    $.ajax({
                        url: fsbhoa_ajax_settings.ajax_url, type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
                        success: (saveResponse) => {
                            if (saveResponse.success) { executeRestore(); }
                            else {
                                btn.prop('disabled', false).text('Restore (Move back in)');
                                alert('Could not restore because the current record has validation errors:\n\n• ' + saveResponse.data.errors.join('\n• '));
                            }
                        },
                        error: () => { btn.prop('disabled', false).text('Restore (Move back in)'); alert('Network error auto-saving.'); }
                    });
                }
            });

            // ----------------------------------------------------------------------
            // 4. Instant Merge Button Handler
            // ----------------------------------------------------------------------
            this.vars.householdPanel.on('click', '.fsbhoa-merge-cardholder-btn', (e) => {
                e.preventDefault();
                let btn = $(e.currentTarget);
                let primaryId = btn.data('primary');
                let duplicateId = btn.data('duplicate');
                let targetName = btn.data('name');

                if (!confirm(`Are you sure you want to merge the manual record into the imported record ("${targetName}")? \n\nAll credentials and data from the manual record will be moved to the imported profile, and the manual duplicate will be permanently deleted.`)) {
                    return;
                }

                btn.prop('disabled', true).text('Merging...');

                $.ajax({
                    url: fsbhoa_ajax_settings.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'fsbhoa_ajax_merge_cardholders',
                        primary_id: primaryId,
                        duplicate_id: duplicateId,
                        security: fsbhoa_ajax_settings.property_search_nonce
                    },
                    success: (response) => {
                        if (response.success) {
                            // If we were editing the manual record, redirect to edit the surviving imported record.
                            let urlParams = new URLSearchParams(window.location.search);
                            let currentEditId = parseInt(urlParams.get('id')) || parseInt(urlParams.get('cardholder_id')) || 0;

                            if (currentEditId == duplicateId) {
                                urlParams.set('action', 'edit_cardholder');
                                urlParams.set('cardholder_id', primaryId);
                                window.location.href = window.location.pathname + '?' + urlParams.toString();
                            } else {
                                window.location.reload();
                            }
                        } else {
                            alert('Merge failed: ' + response.data);
                            btn.prop('disabled', false).text('Merge into imported');
                        }
                    },
                    error: () => {
                        alert('Network error during merge.');
                        btn.prop('disabled', false).text('Merge into imported');
                    }
                });
            });

            // ----------------------------------------------------------------------
            // 5. Instant Purge Button Handler
            // ----------------------------------------------------------------------
            this.vars.householdPanel.on('click', '.fsbhoa-instant-purge-btn', (e) => {
                e.preventDefault();
                let btn = $(e.currentTarget);
                let cardholderId = btn.data('id');
                let targetName = btn.data('name');
                let propertyId = this.vars.propertyIdHiddenInput.val();

                if (!confirm(`Are you sure you want to permanently purge "${targetName}"? \n\nThey will be removed from this view forever, but their historical logs will remain in the database. This CANNOT be undone.`)) {
                    return;
                }

                btn.prop('disabled', true).text('Purging...');

                $.ajax({
                    url: fsbhoa_ajax_settings.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'fsbhoa_ajax_purge_cardholder',
                        cardholder_id: cardholderId,
                        security: fsbhoa_ajax_settings.property_search_nonce
                    },
                    success: (response) => {
                        if (response.success) {
                            let listItem = btn.closest('li');
                            listItem.css({'text-decoration': 'line-through', 'color': '#999'}).fadeOut(400, () => {
                                this.vars.householdPanel.data('loaded-property', '');
                                this.fetchPropertyOccupants(propertyId);
                            });
                        } else {
                            alert('Failed to purge: ' + response.data);
                            btn.prop('disabled', false).text('Purge');
                        }
                    },
                    error: () => {
                        alert('Network error during purge.');
                        btn.prop('disabled', false).text('Purge');
                    }
                });
            });

            // 5. Instant Purge Button Handler
            this.vars.householdPanel.on('click', '.fsbhoa-instant-purge-btn', (e) => {
                e.preventDefault();
                let btn = $(e.currentTarget);
                let cardholderId = btn.data('id');
                let targetName = btn.data('name');
                let propertyId = this.vars.propertyIdHiddenInput.val();

                if (!confirm(`Are you sure you want to permanently purge "${targetName}"? \n\nThey will be removed from this view forever, but their historical logs will remain in the database. This CANNOT be undone.`)) {
                    return;
                }

                btn.prop('disabled', true).text('Purging...');

                $.ajax({
                    url: fsbhoa_ajax_settings.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'fsbhoa_ajax_purge_cardholder',
                        cardholder_id: cardholderId,
                        security: fsbhoa_ajax_settings.property_search_nonce
                    },
                    success: (response) => {
                        if (response.success) {
                            let listItem = btn.closest('li');
                            listItem.css({'text-decoration': 'line-through', 'color': '#999'}).fadeOut(400, () => {
                                this.vars.householdPanel.data('loaded-property', '');
                                this.fetchPropertyOccupants(propertyId);
                            });
                        } else {
                            alert('Failed to purge: ' + response.data);
                            btn.prop('disabled', false).text('Purge');
                        }
                    },
                    error: () => {
                        alert('Network error during purge.');
                        btn.prop('disabled', false).text('Purge');
                    }
                });
            });

            // 6. Copy Household Diagnostic Table to Clipboard
            this.vars.householdPanel.on('click', '.fsbhoa-copy-hh-debug-btn', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                const propId = this.vars.propertyIdHiddenInput.val();
                const hhId = parseInt($btn.data('household-id'), 10) || 0;

                if (!propId) {
                    alert('No property selected.');
                    return;
                }

                $btn.css('opacity', '0.4');

                $.ajax({
                    url: fsbhoa_ajax_settings.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'fsbhoa_copy_household_debug',
                        property_id: propId,
                        household_id: hhId,
                        security: fsbhoa_ajax_settings.property_search_nonce
                    },
                    success: (response) => {
                        $btn.css('opacity', '1');
                        if (response.success) {
                            const textToCopy = response.data;

                            const showCopiedState = () => {
                                const $icon = $btn.find('.dashicons');
                                $icon.removeClass('dashicons-clipboard').addClass('dashicons-yes-alt').css('color', '#137333');
                                setTimeout(() => {
                                    $icon.removeClass('dashicons-yes-alt').addClass('dashicons-clipboard').css('color', '#666');
                                }, 2000);
                            };

                            if (navigator.clipboard && window.isSecureContext) {
                                navigator.clipboard.writeText(textToCopy).then(showCopiedState).catch(() => {
                                    prompt("Copy to clipboard: Ctrl+C, Enter", textToCopy);
                                });
                            } else {
                                const textArea = document.createElement("textarea");
                                textArea.value = textToCopy;
                                textArea.style.position = "fixed";
                                textArea.style.left = "-999999px";
                                textArea.style.top = "-999999px";
                                document.body.appendChild(textArea);
                                textArea.focus();
                                textArea.select();
                                try {
                                    document.execCommand('copy');
                                    showCopiedState();
                                } catch (err) {
                                    prompt("Copy to clipboard: Ctrl+C, Enter", textToCopy);
                                }
                                document.body.removeChild(textArea);
                            }
                        } else {
                            alert('Could not fetch debug data: ' + response.data);
                        }
                    },
                    error: () => {
                        $btn.css('opacity', '1');
                        alert('Network error fetching debug info.');
                    }
                });
            });
        },


        convertSvgToPng: function(svgText) {
            const canvas = document.createElement('canvas');
            const targetWidth = fsbhoa_ajax_settings.photo_width || 640;
            const targetHeight = fsbhoa_ajax_settings.photo_height || 800;

            canvas.width = targetWidth;
            canvas.height = targetHeight;

            canvg(canvas, svgText, {
                ignoreMouse: true,
                ignoreAnimation: true,
                ignoreDimensions: true, 
                scaleWidth: targetWidth, 
                scaleHeight: targetHeight
            });

            const pngDataUri = canvas.toDataURL('image/png');
            this.updateMainPhotoDisplay(pngDataUri);
        },

        handleRemovePhotoButtonClick: function() {
            this.updateMainPhotoDisplay(null);
        },

        handlePrintIdClick: function() {
            var afterSaveInput = $('<input>')
                .attr('type', 'hidden')
                .attr('name', 'fsbhoa_after_save_action')
                .val('print');

            this.vars.cardholderForm.append(afterSaveInput);
            this.vars.cardholderForm.find('button[type="submit"]').first().click();
        },

        handleExportPhotoClick: function() {
            const base64Data = this.vars.photoBase64Input.val();
            const firstName = this.vars.firstNameInput.val() || 'photo';
            const lastName = this.vars.lastNameInput.val() || 'export';

            if (!base64Data) {
                alert('No photo to export.');
                return;
            }

            const dataUrl = 'data:image/png;base64,' + base64Data;
            const safeFirstName = firstName.trim().toLowerCase().replace(/[^a-z0-9]/gi, '_');
            const safeLastName = lastName.trim().toLowerCase().replace(/[^a-z0-9]/gi, '_');
            const filename = safeFirstName + '.' + safeLastName + '.png';

            const link = document.createElement('a');
            link.href = dataUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },


        handleCropButtonClick: function() {
            const imageSrc = this.vars.mainPhotoPreviewImg.attr('src');
            if (imageSrc && imageSrc !== '#') {
                const photoSettings = (typeof fsbhoa_photo_settings !== 'undefined') ? fsbhoa_photo_settings : {};
                FSBHOA_Croppie.open(imageSrc, photoSettings);
            }
        },

        startWebcam: function() {
           this.vars.webcamErrorMessage.hide().text('');

           if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
               const errorMessage = 'Webcam requires a secure connection (HTTPS). Please use an https:// URL to access this page.';
               this.vars.webcamErrorMessage.text(errorMessage).show();
               console.error(errorMessage);
               return; 
           }
           this.vars.removePhotoButton.hide();
           this.vars.exportPhotoButton.hide();
           this.vars.printIdButton.hide();

           navigator.mediaDevices.getUserMedia({ video: true })
               .then((mediaStream) => {
                   this.vars.stream = mediaStream;
                   if (this.vars.videoElement) {
                       this.vars.videoElement.srcObject = this.vars.stream;
                       this.vars.videoElement.play();
                       this.vars.webcamContainer.show();
                   }
                   this.vars.startWebcamButton.hide();
                   this.vars.webcamActiveControls.show();
                   this.vars.fileUploadSection.hide();
               })
               .catch((err) => {
                   let errorMessage = 'Could not access webcam.';
                   if (err.name === "NotAllowedError" || err.name === "PermissionDeniedError") {
                       errorMessage = 'Webcam access was denied. Please allow camera access in your browser settings.';
                   } else if (err.name === "NotFoundError" || err.name === "DevicesNotFoundError") {
                       errorMessage = 'No webcam was found. Please ensure a camera is connected and enabled.';
                   }
                   this.vars.webcamErrorMessage.text(errorMessage).show();
                   console.error('Webcam Error:', err);
               });
        },

        stopWebcam: function() {
            if (this.vars.stream) {
                this.vars.stream.getTracks().forEach(track => track.stop());
                this.vars.stream = null;
            }
            this.updateControlsVisibility();
        },

        captureWebcamPhoto: function() {
            if (this.vars.stream) {
                this.vars.canvasElement.width = this.vars.videoElement.videoWidth;
                this.vars.canvasElement.height = this.vars.videoElement.videoHeight;
                this.vars.canvasElement.getContext('2d').drawImage(this.vars.videoElement, 0, 0);

                const imageDataUrl = this.vars.canvasElement.toDataURL('image/jpeg', 0.9);

                this.updateMainPhotoDisplay(imageDataUrl); 
                this.stopWebcam(); 
            }
        },

        handleFileSelect: function(e) {
            if (e.target.files && e.target.files[0]) {
                const file = e.target.files[0];
                const reader = new FileReader();

                if (file.type === 'image/svg+xml') {
                    reader.onload = (event) => { this.convertSvgToPng(event.target.result); };
                    reader.readAsText(file);
                } else {
                    reader.onload = (event) => { this.updateMainPhotoDisplay(event.target.result); };
                    reader.readAsDataURL(file);
                }
            }
        },

        handleRfidInputChange: function() {
            const rfidValue = this.vars.rfidInput.val();
            const today = new Date().toISOString().slice(0, 10);

            if (rfidValue && rfidValue.length === 8) {
                this.vars.statusDisplaySpan.text('Active');
                this.vars.submittedStatusHidden.val('active');
                this.vars.issueDateDisplay.text(today);
                this.vars.issueDateHidden.val(today);
                this.vars.statusToggleContainer.show();
                this.vars.statusUiToggleCheckbox.prop('checked', true);
                this.vars.toggleLabelSpan.text('(Click to disable)');
                if (this.vars.residentTypeInput.val() === 'Contractor') {
                    this.vars.contractorExpiryContainer.show();
                }
            } else {
                this.vars.statusDisplaySpan.text('Inactive');
                this.vars.submittedStatusHidden.val('inactive');
                this.vars.issueDateDisplay.text('N/A');
                this.vars.issueDateHidden.val('');
                this.vars.statusToggleContainer.hide();
                this.vars.contractorExpiryContainer.hide();
            }
        },

        updateMainPhotoDisplay: function(imageDataUrl) {
            const base64Data = (imageDataUrl && imageDataUrl !== '#') ? imageDataUrl.split(',')[1] || "" : "";
            const finalSrc = (imageDataUrl && imageDataUrl !== '#') ? imageDataUrl : '#';

            this.vars.mainPhotoPreviewImg.attr('src', finalSrc);
            this.vars.photoBase64Input.val(base64Data);

            this.updateControlsVisibility();
        },

        updateControlsVisibility: function() {
            const hiddenInputValue = this.vars.photoBase64Input.val();
            const hasPhoto = this.vars.photoBase64Input.val() !== '';
            const isWebcamActive = !!this.vars.stream;

            this.vars.noPhotoMessage.toggle(!hasPhoto && !isWebcamActive);
            this.vars.mainPhotoPreviewImg.toggle(hasPhoto);

            const showActionButtons = hasPhoto && !isWebcamActive;
            this.vars.removePhotoButton.toggle(showActionButtons);
            this.vars.exportPhotoButton.toggle(showActionButtons);
            this.vars.printIdButton.toggle(showActionButtons);
            this.vars.cropPhotoButton.toggle(showActionButtons);

            this.vars.startWebcamButton.toggle(!isWebcamActive);
            this.vars.fileUploadSection.toggle(!isWebcamActive);

            this.vars.webcamContainer.toggle(isWebcamActive);
            this.vars.webcamActiveControls.toggle(isWebcamActive);
        },

        updateStatusDisplayFromCheckbox: function() {
            if (!this.vars.statusUiToggleCheckbox.length) return;
            const isChecked = this.vars.statusUiToggleCheckbox.is(':checked');
            const today = new Date().toISOString().slice(0, 10);
            const wasPreviouslyDisabled = this.vars.submittedStatusHidden.val() === 'disabled';

            if (isChecked) {
                this.vars.submittedStatusHidden.val('active');
                this.vars.statusDisplaySpan.text('Active');
                this.vars.toggleLabelSpan.text('(Click to disable)');
                if (wasPreviouslyDisabled) {
                    this.vars.issueDateDisplay.text(today);
                    this.vars.issueDateHidden.val(today);
                }
            } else {
                this.vars.submittedStatusHidden.val('disabled');
                this.vars.statusDisplaySpan.text('Disabled');
                this.vars.toggleLabelSpan.text('(Click to enable)');
            }
        },

        handleFormValidation: function(e) {
            const addressText = this.vars.propertySearchInput.val();
            const addressId = this.vars.propertyIdHiddenInput.val();
            const checkedGroups = this.vars.cardholderForm.find('input[name="cardholder_groups[]"]:checked').length;

            if (addressText !== '' && (addressId === '' || addressId === '0')) {
                e.preventDefault(); 
                alert('The address you entered is not valid. Please select a valid property from the dropdown list.');
                this.vars.propertySearchInput.focus();
                return; 
            }

            if (addressId === '' || addressId === '0') {
                e.preventDefault();
                alert('A valid Property Address is required. Please select an address from the list.');
                this.vars.propertySearchInput.focus();
                return; 
            }

            if (checkedGroups === 0) {
                e.preventDefault(); 
                alert('A cardholder must be assigned to at least one permission group. Please select a group before saving.');
                return; 
            }
        },
    };

    App.init();
});


/**
 * Handles the real-time functionality for the Live Activity Monitor page.
 * Connects to a WebSocket server and dynamically updates the UI with events.
 */
document.addEventListener('DOMContentLoaded', function () {
    // These variables are passed from WordPress via wp_localize_script
    const WS_URL = fsbhoa_monitor_vars.ws_url || ''; 
    const STATUS_API_URL = '/wp-json/fsbhoa/v1/monitor/group-status';
    const SCHEDULE_API_URL = '/wp-json/fsbhoa/v1/monitor/current-schedule';
    let gateAccessStatus = {}; // Stores { doorId: true/false } from the API
    let gateHardwareStatus = {}; // Stores { doorId: 'locked'/'unlocked'/'intermediate' } from WebSocket

    // --- DOM Elements ---
    const eventList = document.getElementById('event-list');
    const scheduleName = document.getElementById('current-schedule-name');
    const scheduleIndicator = document.getElementById('fsbhoa-schedule-indicator');
    let logPlaceholder = document.getElementById('log-placeholder'); 
    const connectionStatus = document.getElementById('connection-status');
    const statusDot = connectionStatus ? connectionStatus.querySelector('div') : null;
    const statusText = connectionStatus ? connectionStatus.querySelector('span') : null;
    let lastEventTimestamp = '';
    let maxExpandedEvents = 3;
    
    if (!eventList || !connectionStatus) {
        console.error("Live Monitor UI elements not found. Aborting script.");
        return;
    }
    
    let socket;

    function connect() {
        if (!WS_URL) {
            console.error("WebSocket URL is not defined.");
            updateConnectionStatus('error', 'Configuration Error');
            return;
        }

        updateConnectionStatus('connecting', 'Connecting...');
        socket = new WebSocket(WS_URL);

        socket.onopen = function(event) {
            console.log('WebSocket connection established');
            updateConnectionStatus('connected', 'Connected');
        };

        socket.onmessage = function(event) {
            try {
                const message = JSON.parse(event.data);

                // Route message based on its type
                switch (message.messageType) {
                    case 'initialConfig':
                        maxExpandedEvents = parseInt(message.payload.photoEventLimit, 10) || 3;
                        loadRecentActivity();
                        break;
                    case 'accessEvent':
                        addEventToLog(message.payload);
                        flashGateLight(message.payload.doorRecordId);
                        break;
                    case 'gateStatus':
                        updateGateStatus(message.payload);
                        break;
                    default:
                        // This handles the old, direct event format for backward compatibility
                        if (message.eventType) {
                           addEventToLog(message);
                        } else {
                           console.warn('Received unknown message type:', message.messageType);
                        }
                }
            } catch (e) {
                console.error('Error parsing incoming message:', e);
            }
        };

        socket.onclose = function(event) {
            console.log('WebSocket connection closed. Reconnecting in 3 seconds...');
            updateConnectionStatus('disconnected', 'Disconnected');
            Object.keys(gates).forEach(id => updateGateStatus({ doorRecordId: id, status: 'down' }));
            setTimeout(connect, 3000);
        };

        socket.onerror = function(error) {
            console.error('WebSocket error:', error);
            updateConnectionStatus('error', 'Error');
            socket.close();
        };
    }

    function updateConnectionStatus(state, message) {
        statusText.textContent = message;
        connectionStatus.className = 'flex items-center space-x-2 px-3 py-1 rounded-full text-sm font-medium ';
        statusDot.className = 'w-2 h-2 rounded-full ';

        switch (state) {
            case 'connected':
                connectionStatus.classList.add('bg-green-200', 'text-green-800');
                statusDot.classList.add('bg-green-500');
                break;
            case 'disconnected':
            case 'error':
                connectionStatus.classList.add('bg-red-200', 'text-red-800');
                statusDot.classList.add('bg-red-500');
                break;
            default: // connecting
                connectionStatus.classList.add('bg-yellow-200', 'text-yellow-800');
                statusDot.classList.add('bg-yellow-500');
        }
    }
    

    function sendDoorStateCommand(doorId, stateCode, lightElement) {
        // Temporarily make the light pulse to show a command is being sent
        if (lightElement) {
            lightElement.classList.add('flash');
        }

        const apiEndpoint = '/wp-json/fsbhoa/v1/monitor/set-door-state';
        const postData = {
            door_id: parseInt(doorId),
            state: parseInt(stateCode)
        };

        fetch(apiEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': fsbhoa_monitor_vars.nonce // Add the nonce header
            },
            body: JSON.stringify(postData)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => { throw new Error(err.message || 'Unknown error'); });
            }
            return response.json();
        })
        .then(data => {
            console.log('Command successful:', data.message);
            // The UI will update automatically when the event_service polls the new state.
            setTimeout(() => {
                if (lightElement) lightElement.classList.remove('flash');
            }, 1000);
        })
        .catch(error => {
            console.error('Error setting door state:', error);
            alert('Error sending command: ' + error.message);
            if (lightElement) lightElement.classList.remove('flash');
        });
    }

    function handleGateClick(e) {
        const light = e.target.closest('.gate-light');
        if (!light) {
            return;
        }

        const doorId = light.id.replace('gate-light-', '');
        const gateName = light.title;

        // Create and inject the modal HTML
        const modalHTML = `
            <div id="door-control-overlay" class="fsbhoa-modal-overlay">
                <div class="fsbhoa-modal-content">
                    <h3>Set State for "${gateName}"</h3>
                    <div id="door-control-buttons" class="fsbhoa-modal-buttons">
                        <button class="button-yellow" data-state="1">Unlock by Card (Yellow)</button>
                        <button class="button-green" data-state="2">Unlock (Green)</button>
                        <button class="button-red" data-state="3">Lock (Red)</button>
                        <button class="button-secondary" data-state="cancel">Cancel</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Add a click listener to the new button container
        const buttonContainer = document.getElementById('door-control-buttons');
        buttonContainer.addEventListener('click', function(event) {
            const button = event.target.closest('button');
            if (!button) return;

            const desiredState = button.getAttribute('data-state');

            // Remove the modal from the screen
            document.getElementById('door-control-overlay').remove();

            // If not "Cancel", send the command
            if (desiredState && desiredState !== 'cancel') {
                sendDoorStateCommand(doorId, desiredState, light);
            }
        });
    }






    function createEventCard(eventData, isExpanded) {
        const li = document.createElement('li');
        li.dataset.eventData = JSON.stringify(eventData);

        if (eventData.logId) {
            li.dataset.logId = eventData.logId;
        }

        const hasCardholder = Boolean(eventData.cardholderId);
        if (hasCardholder) {
            li.dataset.cardholderId = eventData.cardholderId;
            li.style.cursor = 'pointer';
            li.title = 'Click to view cardholder details';
        }

        if (isExpanded) {
            let descr = `Event at <span class="font-medium">${eventData.gateName}</span>`;
            if (eventData.controller_identifier === '900001') {
                descr = "QR Lighting " + descr;
            }
            const isGranted = eventData.eventType === 'accessGranted';
            li.className = `p-4 flex items-start space-x-4 border-l-4 ${isGranted ? 'border-green-500 bg-green-50' : 'border-red-500 bg-red-50'} is-expanded`;

            // Apply the inline SVG fallback if no photo URL is provided
            const fallbackSvg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23aaaaaa'%3E%3Cpath d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/%3E%3C/svg%3E";

            let photoSrc = eventData.photoURL;
            let photoClasses = "w-16 h-16 rounded-lg object-cover";
            // Just in case the backend DOES send an empty string
            if (!photoSrc) {
                photoSrc = fallbackSvg;
                photoClasses += " p-2 bg-gray-200";
            }

            const nameClass = hasCardholder ? 'text-lg font-semibold text-blue-900 underline' : 'text-lg font-semibold text-gray-900';
            li.innerHTML = `
                <img class="${photoClasses}" src="${photoSrc}" alt="${eventData.cardholderName}">
                <div class="flex-1 event-text-content">
                    <p class="${nameClass}"<${eventData.cardholderName}></p>
                    <p class="text-gray-500">${eventData.streetAddress}</p>
                    <p class="text-gray-600">${descr}</p>
                    <p class="text-yellow-700 font-bold event-amenity">${eventData.amenity ? `Amenity: ${eventData.amenity}` : ''}</p>
                    <p class="text-sm ${isGranted ? 'text-green-700' : 'text-red-700'} font-medium mt-1">${eventData.eventMessage}</p>
                </div>

                <time class="text-sm text-gray-500">${eventData.timestamp}</time>
            `;
        } else {
            let collapsedAt = `at <span class="font-medium">${eventData.gateName}</span>`;
            if (eventData.controller_identifier === '900001') {
                collapsedAt = "QR Lighting " + collapsedAt;
            }
            const isGranted = eventData.eventType === 'accessGranted';
            li.className = 'px-4 py-2 text-sm text-gray-600';
            const nameSpan = hasCardholder
                ? `<span class="font-medium text-blue-900 underline">${eventData.cardholderName}></span>`
                : `<span>${eventData.cardholderName}></span>`;

            li.innerHTML = `
                <time class="font-mono text-gray-500 mr-2">[${eventData.timestamp}]</time> 
                ${nameSpan} ${collapsedAt} ${eventData.amenity ? `(${eventData.amenity})` : ''}
                (<span class="${isGranted ? 'text-green-600' : 'text-red-600'}">${eventData.eventMessage}</span>)
            `;
        }
        return li;
    }

    function addEventToLog(eventData) {
        console.log("Monitor: Received Event:", eventData);
        
        const uniqueId = eventData.logId;
        let existingRow = null;

        if (uniqueId) {
            existingRow = document.querySelector(`li[data-log-id="${uniqueId}"]`);
        }

        if (existingRow) {
            console.log("Monitor: Updating existing row for ID:", uniqueId);
            
            // Check if the current card is expanded so we don't collapse it on update
            const wasExpanded = existingRow.classList.contains('is-expanded');
            
            // Create the new card with the updated data
            const updatedCard = createEventCard(eventData, wasExpanded);
            
            // Swap them out in place
            existingRow.replaceWith(updatedCard);
            return; // Stop here, we are done.
        }

        // 1. Check if the new event's timestamp is the same as the last one.
        if (eventData.timestamp === lastEventTimestamp) {
            return; // If it's the same, stop and ignore the event.
        }

        if (logPlaceholder) {
            logPlaceholder.remove();
            logPlaceholder = null;
        }

        const newEventCard = createEventCard(eventData, true);
        eventList.prepend(newEventCard);

        const allEvents = eventList.querySelectorAll('li');
        
        // Use the correct camelCase variable name here
        if (allEvents.length > maxExpandedEvents) { 
            // And also here
            const cardToCollapse = allEvents[maxExpandedEvents]; 
            if (cardToCollapse && cardToCollapse.classList.contains('is-expanded')) {
                const originalData = JSON.parse(cardToCollapse.dataset.eventData);
                const collapsedCard = createEventCard(originalData, false);
                cardToCollapse.replaceWith(collapsedCard);
            }
        }

        while (eventList.children.length > 50) {
            eventList.removeChild(eventList.lastChild);
        }

        // 2. Update the 'lastEventTimestamp' with the new timestamp.
        lastEventTimestamp = eventData.timestamp;
    }


    const GATES_API_URL = '/wp-json/fsbhoa/v1/monitor/gates';
    const mapContainer = document.getElementById('map-container');
    let gates = {}; // Store gate data by door_record_id

    async function initializeMap() {
        if (!mapContainer) return;

        // CHECK 1: Do we have pre-loaded data from PHP?
        if (fsbhoa_monitor_vars.preloaded_gates) {
            console.log("Monitor: Using pre-loaded gate data.");
            renderGates(fsbhoa_monitor_vars.preloaded_gates);
            return; // Skip the network request!
        }

        // CHECK 2: Fallback to network request if pre-load failed
        try {
            console.log("Monitor: Fetching gate data from API...");
            const response = await fetch(GATES_API_URL);
            if (!response.ok) {
                throw new Error(`Failed to fetch gates: ${response.statusText}`);
            }
            const gatesData = await response.json();
            renderGates(gatesData);
        } catch (error) {
            console.error("Error initializing map:", error);
            mapContainer.innerHTML = '<p style="text-align:center; color:red; padding-top:20px;">Could not load gate map data.</p>';
        }
    }

    // Helper function to draw the dots (extracted to avoid code duplication)
    function renderGates(gatesData) {
        gatesData.forEach(gate => {
            // Skip system doors used by automated tests
            if (gate.door_role === 'TEST') {
                return;
            }
            gates[gate.door_record_id] = gate;

            const light = document.createElement('div');
            light.id = `gate-light-${gate.door_record_id}`;
            light.className = 'gate-light status-down'; // Default to down
            light.style.left = `${gate.map_x}%`;
            light.style.top = `${gate.map_y}%`;
            light.title = gate.friendly_name;
            mapContainer.appendChild(light);
        });
    }

    async function loadRecentActivity() {
        const ACTIVITY_API_URL = '/wp-json/fsbhoa/v1/monitor/recent-activity';
        try {
            const response = await fetch(ACTIVITY_API_URL);
            if (!response.ok) {
                throw new Error(`Failed to fetch recent activity: ${response.statusText}`);
            }
            const events = await response.json();

            // If there are events, add them to the log
            if (events.length > 0) {
                // The API returns newest first, so we loop backwards to add oldest first
                for (let i = events.length - 1; i >= 0; i--) {
                    addEventToLog(events[i]);
                }
            }
        } catch (error) {
            console.error("Error loading recent activity:", error);
            // Optionally, display an error in the log
            const eventList = document.getElementById('event-list');
            if (eventList) {
                const placeholder = document.getElementById('log-placeholder');
                if (placeholder) {
                    placeholder.textContent = 'Could not load recent activity.';
                }
            }
        }
    }

    function updateGateStatus(statusData) {
        const { doorRecordId, status } = statusData;
        
        // 1. Update the data model
        gateHardwareStatus[doorRecordId] = status;
        
        // 2. Trigger a visual refresh
        refreshAllGateVisuals();
    }

    function flashGateLight(doorRecordId) {
        const light = document.getElementById(`gate-light-${doorRecordId}`);
        if (light) {
            light.classList.add('flash');
            setTimeout(() => {
                light.classList.remove('flash');
            }, 700); // Must match animation duration
        }
    }

    async function updateCurrentSchedule() {
        if (!scheduleName || !scheduleIndicator) return;
        try {
            const response = await fetch(SCHEDULE_API_URL);
            if (!response.ok) return; // Silent fail
            const data = await response.json();

            if (data && data.schedule_name) {
                scheduleName.textContent = data.schedule_name;
                scheduleIndicator.classList.remove('hidden'); // Reveal once data is loaded
            }
        } catch (e) {
            console.warn("Could not fetch current schedule", e);
        }
    }

    /**
     * Sets up the functionality for the Show/Hide Map button.
     * Corrected to use a callback function for updating the button text.
     */
    function setupMapViewToggle() {
        var $mapSection = jQuery('#fsbhoa-map-section');
        var $toggleButton = jQuery('#fsbhoa-toggle-map-btn');

        if ($toggleButton.length === 0) {
            return;
        }

        $toggleButton.on('click', function() {
            // We pass a function directly into slideToggle.
            // This function will execute automatically after the 200ms animation.
            $mapSection.slideToggle(200, function() {
                // Now, check the visibility and set the text
                if ($mapSection.is(':visible')) {
                    $toggleButton.text('Hide Map');
                } else {
                    $toggleButton.text('Show Map');
                }
            });
        });
    }

    function setupEventListClickHandlers() {
        const handleCardClick = function (e) {
            const item = e.target.closest('li[data-cardholder-id]');
            if (!item) return;

            const cardholderId = item.dataset.cardholderId;
            if (!cardholderId) return;

            // Target URL for cardholder edit
            const editUrl = `/cardholder/?action=edit_cardholder&cardholder_id=${encodeURIComponent(cardholderId)}`;
            window.open(editUrl, '_blank');
        };

        const pedList = document.getElementById('event-list');
        if (pedList) {
            pedList.addEventListener('click', handleCardClick);
        }

        const vehList = document.getElementById('vehicle-event-list');
        if (vehList) {
            vehList.addEventListener('click', handleCardClick);
        }
    }

    async function updateAccessStatus() {
        try {
            const response = await fetch(STATUS_API_URL);
            if (!response.ok) return; // Silent fail
            const newStatus = await response.json();
            
            // Only update DOM if something changed to avoid flicker/reflow
            if (JSON.stringify(newStatus) !== JSON.stringify(gateAccessStatus)) {
                gateAccessStatus = newStatus;
                refreshAllGateVisuals();
            }
        } catch (e) {
            console.warn("Could not fetch group status", e);
        }
    }

    function refreshAllGateVisuals() {
        // Loop through all known gates (from the hardware status map)
        // If we don't have hardware status yet, we can't render much, but iterate map elements.
        const allLights = document.querySelectorAll('.gate-light');
        
        allLights.forEach(light => {
            const doorId = light.id.replace('gate-light-', '');
            // Get last known hardware status (default to 'down' if unknown)
            const hwStatus = gateHardwareStatus[doorId] || 'down';
            
            // Get access status (true = Open for Group, false = Closed)
            // Default to false (solid yellow) if unknown
            const isAccessible = gateAccessStatus[doorId] === true; 
            
            light.className = 'gate-light'; // Reset base class
            
            switch (hwStatus) {
                case 'intermediate':
                    // intermediate means "Controlled Mode" (Yellow)
                    // If accessible -> Throb. If not -> Solid.
                    if (isAccessible) {
                        light.classList.add('status-intermediate-throb');
                    } else {
                        light.classList.add('status-intermediate');
                    }
                    break;
                case 'unlocked':
                    light.classList.add('status-unlocked'); // Green (always solid)
                    break;
                case 'locked':
                    light.classList.add('status-locked'); // Red (always solid)
                    break;
                case 'down':
                default:
                    light.classList.add('status-down'); // Grey
                    break;
            }
        });
    }

    window.showCardholderSummaryModal =async function showCardholderSummaryModal(cardholderId) {
        const modal = document.getElementById('fsbhoa-cardholder-modal');
        const modalContent = document.getElementById('fsbhoa-cardholder-modal-content');
        if (!modal || !modalContent) return;

        modalContent.innerHTML = '<div style="background:#fff; padding:24px; border-radius:8px; text-align:center;"><p>Loading cardholder details...</p></div>';
        modal.style.display = 'flex';

        try {
            const endpoint = `/wp-json/fsbhoa/v1/monitor/cardholder-summary?cardholder_id=${encodeURIComponent(cardholderId)}`;
            const response = await fetch(endpoint);
            if (!response.ok) {
                throw new Error(`Failed to load cardholder (${response.statusText})`);
            }
            const data = await response.json();
            if (data && data.html) {
                modalContent.innerHTML = data.html;
            } else {
                modalContent.innerHTML = '<div style="background:#fff; padding:24px; border-radius:8px; text-align:center;"><p style="color:red;">Error loading cardholder card.</p></div>';
            }
        } catch (err) {
            console.error('Error fetching cardholder summary:', err);
            modalContent.innerHTML = `<div style="background:#fff; padding:24px; border-radius:8px; text-align:center;"><p style="color:red;">${err.message}</p></div>`;
        }
    }

    function setupEventListClickHandlers() {
        const handleLogClick = function(e) {
            const item = e.target.closest('li[data-cardholder-id]');
            if (!item) return;

            const cardholderId = item.dataset.cardholderId;
            if (!cardholderId || cardholderId === '0' || cardholderId === 'null') {
                return;
            }

            e.preventDefault();
            showCardholderSummaryModal(cardholderId);
        };

        const pedList = document.getElementById('event-list');
        if (pedList) {
            pedList.addEventListener('click', handleLogClick);
        }

        const vehList = document.getElementById('vehicle-event-list');
        if (vehList) {
            vehList.addEventListener('click', handleLogClick);
        }
    }

    // Add the main event listener to the map container
    if (mapContainer) {
        mapContainer.addEventListener('click', handleGateClick);
    }

    // Run all initialization tasks, then connect the WebSocket.
    Promise.all([
        initializeMap(),
        updateAccessStatus(),
        updateCurrentSchedule()
    ]).then(() => {
        setupEventListClickHandlers();
        connect();
        setInterval(updateAccessStatus, 60000); // Poll every 60 seconds
        setInterval(updateCurrentSchedule, 60000);
    });
});

<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML structure for the Live Activity Monitor page.
 */
function fsbhoa_render_live_monitor_view() {
    ?>
<div class="fsbhoa-center-button-container">
       <button id="fsbhoa-toggle-map-btn" class="button">Hide Map</button>
    </div>

    <div class="fsbhoa-frontend-wrap monitor-page-override">

        <div id="fsbhoa-map-section">
            <div class="bg-white rounded-xl shadow-md p-6 mb-8 max-w-4xl mx-auto">
                <h2 class="text-xl font-semibold mb-4">Community Status Map</h2>
                
                <div style="display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start;">
                    
                    <div id="map-container" class="relative" style="flex: 1; min-width: 300px;">
                        <img src="<?php echo esc_url(get_option('fsbhoa_monitor_map_url', '')); ?>" alt="Community Map" style="display: block; width: 100%; height: auto; border-radius: 0.5rem;">

                        <div id="connection-status" class="flex items-center space-x-2 px-3 py-1 rounded-full bg-yellow-200 text-yellow-800 text-sm font-medium">
                            <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
                            <span>Connecting...</span>
                        </div>
                    </div>

                    <div id="fsbhoa-gate-legend" style="width: 220px; flex-shrink: 0; border-radius: 8px; background: #f9f9f9; padding: 15px; border: 1px solid #ddd;">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-4" style="margin-top:0; border-bottom: 1px solid #eee; padding-bottom: 5px;">Status Key</h3>
                        
                        <div style="display: flex; flex-direction: column; gap: 16px;">
                            
                            <div class="flex items-center">
                                <div style="width: 30px; display: flex; justify-content: center; position: relative;">
                                    <div class="gate-light status-unlocked" style="position: static; transform: none; background-color: #22c55e; color: #22c55e;"></div>
                                </div>
                                <span class="text-sm font-medium text-gray-700 ml-2">Open</span>
                            </div>
                    
                            <div class="flex items-center">
                                <div style="width: 30px; display: flex; justify-content: center; position: relative;">
                                    <div class="gate-light status-locked" style="position: static; transform: none; background-color: #ef4444; color: #ef4444;"></div>
                                </div>
                                <span class="text-sm font-medium text-gray-700 ml-2">Closed</span>
                            </div>
                    
                            <div class="flex items-center">
                                <div style="width: 30px; display: flex; justify-content: center; position: relative;">
                                    <div class="gate-light status-intermediate" style="position: static; transform: none; background-color: #f59e0b; color: #f59e0b;"></div>
                                </div>
                                <span class="text-sm font-medium text-gray-700 ml-2">Controlled</span>
                            </div>
                    
                            <div class="flex items-center">
                                <div style="width: 30px; display: flex; justify-content: center; position: relative; height: 30px; align-items: center;">
                                    <div class="gate-light status-intermediate-throb" 
                                         style="position: relative; 
                                                display: block; 
                                                width: 14px;   /* Shrink base to compensate for pulse */
                                                height: 14px;  /* Shrink base to compensate for pulse */
                                                background-color: #f59e0b; 
                                                color: #f59e0b; 
                                                animation: pulse 2s infinite; 
                                                box-shadow: 0 0 5px #f59e0b;">
                                    </div>
                                </div>
                                <span class="text-sm font-medium text-gray-700 ml-2">Resident Access</span>
                            </div>

                            <hr style="border: 0; border-top: 1px solid #eee; margin: 8px 0;">
                            <p style="font-size: 10px; color: #999; line-height: 1.2; font-style: italic;">
                                Note: Throbbing indicates the gate is currently following a resident access schedule.
                            </p>
                            <div id="fsbhoa-schedule-indicator" class="mt-4 p-3 rounded-lg bg-blue-50 border border-blue-200 text-sm hidden">
                                <span class="font-bold text-blue-800 block mb-1">Active Schedule:</span>
                                <span id="current-schedule-name" class="text-blue-900 font-medium">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
       </div>

       <div id="activity-log-section">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 24px; max-width: 1400px; margin: 0 auto;">

                <?php if ( Fsbhoa_Vehicle_Log_REST_API::show_monitor_column() ) : ?>
                <!-- Vehicle Activity (ac_vehicle_log; filled by fsbhoa-vehicle-monitor.js) -->
                <div id="vehicle-log-column">
                    <h2 class="text-xl font-semibold mb-4" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Vehicle Gate Traffic</span>
                        <button type="button" id="vehicle-alert-test-sound" title="Play the alert sound, to check the speakers (this also turns the sound on for this page)" style="font-size: 13px; font-weight: normal; padding: 4px 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #1f2937; cursor: pointer;">&#128276; Test sound</button>
                    </h2>
                    <?php $vehicle_lanes = Fsbhoa_Vehicle_Log_REST_API::monitor_lanes(); ?>
                    <?php if ( ! empty( $vehicle_lanes ) ) : ?>
                    <!-- One lane at a time (fsbhoa_monitor_vehicle_lanes; filtered by fsbhoa-vehicle-monitor.js) -->
                    <div id="vehicle-lane-filter" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;">
                        <button type="button" data-lane="" class="vehicle-lane-button">All</button>
                        <?php foreach ( $vehicle_lanes as $lane ) : ?>
                        <button type="button" data-lane="<?php echo esc_attr( $lane ); ?>" class="vehicle-lane-button"><?php echo esc_html( $lane ); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <!-- Lane alerts that are on, such as a broken arm (/vehicle-alerts; filled by fsbhoa-vehicle-monitor.js) -->
                    <ul id="vehicle-alert-list" role="alert" style="list-style: none; margin: 0; padding: 0;"></ul>
                    <div class="bg-white rounded-xl shadow-md overflow-hidden">
                        <div id="vehicle-log-container" style="height: 36rem; overflow-y: auto;">
                            <ul id="vehicle-event-list" class="divide-y divide-gray-200">
                                <li id="vehicle-log-placeholder" class="p-4 text-center text-gray-500">
                                    Waiting for vehicle events...
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Extension plugins add their own activity columns here, left of the pedestrian log.
                do_action( 'fsbhoa_monitor_activity_columns' );
                ?>

                <?php if ( Fsbhoa_Monitor_REST_API::show_pedestrian_column() ) : ?>
                <!-- Pedestrian Activity (ac_access_log; filled by fsbhoa-live-monitor.js) -->
                <div id="pedestrian-log-column">
                    <h2 class="text-xl font-semibold mb-4">Pedestrian Gate Access</h2>
                    <?php $pedestrian_gates = Fsbhoa_Monitor_REST_API::monitor_gates(); ?>
                    <?php if ( ! empty( $pedestrian_gates ) ) : ?>
                    <!-- One gate at a time (ac_doors; filtered by fsbhoa-live-monitor.js). More gates than
                         lanes, so smaller buttons, wrapping to a second row or more -->
                    <div id="pedestrian-gate-filter" style="display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 12px;">
                        <button type="button" data-gate="" class="pedestrian-gate-button">All</button>
                        <?php foreach ( $pedestrian_gates as $gate ) : ?>
                        <button type="button" data-gate="<?php echo esc_attr( $gate ); ?>" class="pedestrian-gate-button"><?php echo esc_html( $gate ); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="bg-white rounded-xl shadow-md overflow-hidden">
                        <div id="event-log-container" style="height: 36rem; overflow-y: auto;">
                            <ul id="event-list" class="divide-y divide-gray-200">
                              <li id="log-placeholder" class="p-4 text-center text-gray-500">
                                 Waiting for live events...
                              </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>


        <!-- Lightbox Modal for Cardholder Summary Card -->
        <div id="fsbhoa-cardholder-modal" class="fsbhoa-modal-overlay" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 99999; justify-content: center; align-items: center; padding: 20px; overflow-y: auto;" onclick="if(event.target === this) this.style.display='none';">
            <div id="fsbhoa-cardholder-modal-content" style="max-width: 800px; width: 100%; max-height: 90vh; overflow-y: auto; border-radius: 8px;" onclick="event.stopPropagation();">
                <!-- Rendered summary card is dynamically injected here -->
            </div>
        </div>
        <?php if ( Fsbhoa_Vehicle_Log_REST_API::show_monitor_column() ) : ?>
        <!-- Lightbox Modal for a vehicle's plate, scene or overview photo (opened by openVehicleScene()) -->
        <div id="fsbhoa-vehicle-modal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 99999; justify-content: center; align-items: center; padding: 20px;" onclick="this.style.display='none';">
            <div style="max-width: 90vw; max-height: 90vh; text-align: center;" onclick="event.stopPropagation();">
                <img id="fsbhoa-modal-img" src="" alt="Full Scene" style="max-width: 100%; max-height: 80vh; border-radius: 8px;">
                <div style="margin-top: 10px;">
                    <button type="button" class="button" onclick="document.getElementById('fsbhoa-vehicle-modal').style.display='none';">Close</button>
                </div>
            </div>
        </div>
        <!-- A card's recorded video (fsbhoa_monitor_vehicle_video; played by fsbhoa-vehicle-monitor.js) -->
        <div id="fsbhoa-vehicle-video-modal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 99999; justify-content: center; align-items: center; padding: 20px;">
            <div style="width: min(1280px, 92vw); text-align: center; color: #fff;">
                <div id="fsbhoa-video-title" style="font-size: 16px; font-weight: 600; margin-bottom: 8px;"></div>
                <div id="fsbhoa-video-cameras" style="display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin-bottom: 8px;"></div>
                <video id="fsbhoa-video-player" controls autoplay muted playsinline style="width: 100%; max-height: 72vh; background: #000; border-radius: 8px;"></video>
                <div id="fsbhoa-video-status" style="font-size: 13px; color: #cbd5e1; margin-top: 6px; min-height: 18px;"></div>
                <div style="margin-top: 8px; display: flex; gap: 6px; justify-content: center; flex-wrap: wrap;">
                    <button type="button" class="button" data-video-shift="-30">&#9664; 30 s earlier</button>
                    <button type="button" class="button" data-video-shift="30">30 s later &#9654;</button>
                    <button type="button" class="button" id="fsbhoa-video-quality">Sharper</button>
                    <button type="button" class="button" id="fsbhoa-video-close">Close</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php
        // Extension plugins add their own overlays (lightboxes, dialogs) here.
        do_action( 'fsbhoa_monitor_modals' );
        ?>
    <?php
}

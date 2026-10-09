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

                <?php
                // Extension plugins add their own activity columns here, left of the pedestrian log.
                do_action( 'fsbhoa_monitor_activity_columns' );
                ?>

                <!-- Pedestrian Activity -->
                <div id="pedestrian-log-column">
                    <h2 class="text-xl font-semibold mb-4">Pedestrian Gate Access</h2>
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

            </div>
        </div>


        <!-- Lightbox Modal for Cardholder Summary Card -->
        <div id="fsbhoa-cardholder-modal" class="fsbhoa-modal-overlay" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 99999; justify-content: center; align-items: center; padding: 20px; overflow-y: auto;" onclick="if(event.target === this) this.style.display='none';">
            <div id="fsbhoa-cardholder-modal-content" style="max-width: 800px; width: 100%; max-height: 90vh; overflow-y: auto; border-radius: 8px;" onclick="event.stopPropagation();">
                <!-- Rendered summary card is dynamically injected here -->
            </div>
        </div>
        <?php
        // Extension plugins add their own overlays (lightboxes, dialogs) here.
        do_action( 'fsbhoa_monitor_modals' );
        ?>
    <?php
}

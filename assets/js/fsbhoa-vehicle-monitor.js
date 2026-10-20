(function() {
    document.addEventListener('DOMContentLoaded', function() {
        const vehicleEventList = document.getElementById('vehicle-event-list');
        if (!vehicleEventList) return;

        let vehiclePlaceholder = document.getElementById('vehicle-log-placeholder');

        // The vehicle routes require a logged-in admin. fetch() sends the nonce as a header;
        // <img> can't, so image URLs carry it as _wpnonce.
        const nonce = (window.fsbhoa_vehicle_vars && window.fsbhoa_vehicle_vars.nonce) || '';
        const nonceParam = '&_wpnonce=' + encodeURIComponent(nonce);
        let lastKnownId = 0;

        // Escape text from the server before it goes into innerHTML.
        function esc(value) {
           return String(value == null ? '' : value)
               .replace(/&/g, '&amp;')
               .replace(/</g, '&lt;')
               .replace(/>/g, '&gt;')
               .replace(/"/g, '&quot;')
               .replace(/'/g, '&#39;');
        }

        function isTrue(value) {
           return value == 1 || value === true;
        }

        // event_timestamp is local time "YYYY-MM-DD HH:MM:SS[.mmm]"; show it like the
        // pedestrian log does ("3:07:42 PM").
        function formatTime(timestamp) {
           const m = /^\d{4}-\d{2}-\d{2} (\d{2}):(\d{2}):(\d{2})/.exec(timestamp || '');
           if (!m) return '';
           const hour = parseInt(m[1], 10);
           return ((hour % 12) || 12) + ':' + m[2] + ':' + m[3] + ' ' + (hour < 12 ? 'AM' : 'PM');
        }

        function createCard(event) {
           const li = document.createElement('li');
           const logId = parseInt(event.vehicle_log_id, 10);
           li.dataset.logId = logId;

           const isCircumvention = parseInt(event.is_circumvention, 10) === 1;
           const isExit = event.direction === 'exit';
           const borderStyle = isCircumvention
               ? 'border-left: 4px solid #ef4444; background: #fef2f2;'
               : (isExit
                   ? 'border-left: 4px solid #94a3b8; background: #f8fafc;'
                   : 'border-left: 4px solid #3b82f6; background: #eff6ff;');

           const plate = event.lpr_plate_string || 'NO PLATE';
           const gate = event.gate_identifier || 'Vehicle Gate';
           const authPrefixes = {
               DK_WINDSHIELD: 'Tag ',
               DK_ENTRY_CODE: 'Gate Code #',
               DK_DIR_CODE: 'DIR: '
           };
           // No credential and no circumvention: an event from a camera alone (a plate read
           // not matched with a credential, or an exit), so there is nothing to say.
           const auth = event.auth_id
               ? ((authPrefixes[event.auth_type] || 'Auth: ') + event.auth_id)
               : (isCircumvention ? 'NO CREDENTIAL' : '');
           // Unless the lane's name already says so ("South Exit")
           const exitBadge = isExit && !/exit/i.test(gate)
               ? ' <span style="font-size: 10px; font-weight: bold; color: #475569; background: #e2e8f0; padding: 1px 6px; border-radius: 4px; margin-left: 4px;">EXIT</span>'
               : '';
           const time = formatTime(event.event_timestamp);

           const lprUrl = '/wp-json/fsbhoa/v1/vehicle-image/' + logId + '?type=lpr' + nonceParam;
           const contextUrl = '/wp-json/fsbhoa/v1/vehicle-image/' + logId + '?type=context' + nonceParam;
           const overviewUrl = '/wp-json/fsbhoa/v1/vehicle-image/' + logId + '?type=overview' + nonceParam;
           const hasLpr = isTrue(event.has_lpr_img);
           const hasContext = isTrue(event.has_context_img);
           const hasOverview = isTrue(event.has_overview_img);

           let warnBanner = '';
           if (isCircumvention) {
               warnBanner = '<div style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; margin-top: 4px; display: inline-block;">&#9888; Gate Tripped Without Auth</div>';
           }

           // One thumbnail that opens its full photo in the lightbox.
           function thumb(thumbUrl, fullUrl, alt, title, caption) {
               return '<div style="cursor: pointer; text-align: center;" onclick="openVehicleScene(\'' + fullUrl + '\')" title="' + title + '">' +
                  '<img src="' + thumbUrl + '" alt="' + esc(alt) + '" style="width: 80px; height: 50px; object-fit: cover; border-radius: 4px; border: 1px solid #cbd5e1; background: #e2e8f0;" onerror="this.style.display=\'none\';">' +
                 '<div style="font-size: 9px; color: #64748b; margin-top: 2px;">' + caption + '</div>' +
               '</div>';
           }

           // The first thumbnail is the plate crop when there is one; clicking opens the
           // scene photo when there is one. With only one of the two, it is used for both.
           // The second is the overview camera's photo of the car at the gate.
           let thumbs = '';
           if (hasLpr || hasContext) {
               thumbs += thumb(hasLpr ? lprUrl : contextUrl, hasContext ? contextUrl : lprUrl, plate,
                   hasContext ? 'Click to view full context scene' : 'Click to enlarge plate', 'Plate');
           }
           if (hasOverview) {
               thumbs += thumb(overviewUrl, overviewUrl, gate, 'Click to view the overview camera\'s photo', 'Gate');
           }
           const imgBox = thumbs ? '<div style="flex-shrink: 0; display: flex; gap: 6px;">' + thumbs + '</div>' : '';

           // When a credential value belongs to more than one person (such as a shared
           // household PIN), the server picks one; say how many others there are.
           const otherMatches = (parseInt(event.credential_matches, 10) || 0) - 1;
           const othersNote = otherMatches > 0
               ? ' <span style="font-size: 11px; color: #64748b;" title="This credential belongs to more than one person">(+' + otherMatches + ' other' + (otherMatches > 1 ? 's' : '') + ')</span>'
               : '';

           let cardholderHtml = '';
           const chId = parseInt(event.cardholder_id, 10);
           if (chId > 0) {
               cardholderHtml = '<div style="margin-top: 4px;">' +
                  '<a href="#" onclick="if(window.showCardholderSummaryModal){window.showCardholderSummaryModal(' + chId + ');} return false;" style="font-size: 13px; font-weight: 600; color: #1d4ed8; text-decoration: underline; cursor: pointer;">' +
                  esc(event.cardholder_name || 'View Cardholder') +
                  '</a>' + othersNote +
               '</div>';
           } else if (event.cardholder_name) {
               cardholderHtml = '<div style="font-size: 12px; color: #64748b; margin-top: 4px;">' + esc(event.cardholder_name) + othersNote + '</div>';
           }

           li.setAttribute('style', 'padding: 16px; display: flex; gap: 16px; align-items: flex-start; ' + borderStyle);
           li.innerHTML = imgBox +
               '<div style="flex: 1; min-width: 0;">' +
                  '<div style="display: flex; justify-content: space-between; align-items: baseline;">' +
                    '<span style="font-size: 16px; font-weight: bold; font-family: monospace; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; border: 1px solid #cbd5e1; color: #0f172a;">' + esc(plate) + '</span>' +
                    '<time style="font-size: 12px; color: #64748b; font-family: monospace;">' + esc(time) + '</time>' +
                  '</div>' +
                  '<div style="font-size: 13px; color: #334155; margin-top: 4px; font-weight: 500;">' +
                    esc(gate) + exitBadge +
                    (auth ? ' &#x2022; <span style="color: #64748b;">' + esc(auth) + '</span>' : '') +
                  '</div>' +
                  cardholderHtml +
                  warnBanner +
               '</div>';
           return li;
        }

        function addEvent(event) {
           if (vehiclePlaceholder) {
               vehiclePlaceholder.remove();
               vehiclePlaceholder = null;
           }
           const id = parseInt(event.vehicle_log_id, 10);
           if (id > lastKnownId) lastKnownId = id;

           const card = createCard(event);
           vehicleEventList.prepend(card);

           while (vehicleEventList.children.length > 50) {
               vehicleEventList.removeChild(vehicleEventList.lastChild);
           }
        }

        async function fetchRecent() {
           try {
               const url = lastKnownId > 0
                  ? ('/wp-json/fsbhoa/v1/vehicle-recent?since=' + lastKnownId)
                  : '/wp-json/fsbhoa/v1/vehicle-recent';
               const resp = await fetch(url, { headers: { 'X-WP-Nonce': nonce } });
               if (!resp.ok) return;
               const events = await resp.json();
               if (Array.isArray(events) && events.length > 0) {
                  if (lastKnownId === 0) {
                     for (let i = events.length - 1; i >= 0; i--) {
                        addEvent(events[i]);
                     }
                  } else {
                     events.forEach(addEvent);
                  }
               }
           } catch (err) {
               console.warn('Vehicle monitor fetch error:', err);
           }
        }

        window.openVehicleScene = function(src) {
           const modal = document.getElementById('fsbhoa-vehicle-modal');
           const img = document.getElementById('fsbhoa-modal-img');
           if (modal && img) {
               img.src = src;
               modal.style.display = 'flex';
           }
        };

        fetchRecent();
        setInterval(fetchRecent, 3000);
    });
})();

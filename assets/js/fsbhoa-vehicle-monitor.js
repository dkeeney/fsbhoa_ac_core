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

        // A lane alert's row (such as a broken arm): red when it starts, green when it ends,
        // with the overview camera's photo of the lane. A notice (such as the arm held up) is
        // amber when it starts and grey when it ends, and has no alarm.
        function createAlertCard(event) {
           const li = document.createElement('li');
           const logId = parseInt(event.vehicle_log_id, 10);
           li.dataset.logId = logId;
           const active = isTrue(event.alert_active);
           const notice = isTrue(event.alert_notice);
           const look = notice
               ? (active ? { border: '#d97706', bg: '#fffbeb', text: '#92400e', icon: '&#9432; ' }
                         : { border: '#94a3b8', bg: '#f8fafc', text: '#334155', icon: '&#10003; ' })
               : (active ? { border: '#dc2626', bg: '#fee2e2', text: '#991b1b', icon: '&#9888; ' }
                         : { border: '#16a34a', bg: '#f0fdf4', text: '#166534', icon: '&#10003; ' });
           const gate = event.gate_identifier || 'Vehicle Gate';
           const overviewUrl = '/wp-json/fsbhoa/v1/vehicle-image/' + logId + '?type=overview' + nonceParam;
           const photo = isTrue(event.has_overview_img)
               ? '<div style="flex-shrink: 0; cursor: pointer;" onclick="openVehicleScene(\'' + overviewUrl + '\')" title="Click to view the overview camera\'s photo">' +
                    '<img src="' + overviewUrl + '" alt="' + esc(gate) + '" style="width: 80px; height: 50px; object-fit: cover; border-radius: 4px; border: 1px solid #cbd5e1;" onerror="this.style.display=\'none\';">' +
                 '</div>'
               : '';
           li.setAttribute('style', 'padding: 16px; display: flex; gap: 16px; align-items: flex-start; ' +
               'border-left: 6px solid ' + look.border + '; background: ' + look.bg + ';');
           li.innerHTML = photo +
               '<div style="flex: 1; min-width: 0;">' +
                  '<div style="display: flex; justify-content: space-between; align-items: baseline;">' +
                    '<span style="font-size: 16px; font-weight: bold; color: ' + look.text + ';">' +
                       look.icon + esc(event.alert_text || event.alert_key) + '</span>' +
                    '<time style="font-size: 12px; color: #64748b; font-family: monospace;">' + esc(formatTime(event.event_timestamp)) + '</time>' +
                  '</div>' +
                  '<div style="font-size: 13px; color: #334155; margin-top: 4px; font-weight: 500;">' + esc(gate) + '</div>' +
                  '<div class="fsbhoa-alert-ack" style="font-size: 12px; color: #64748b; margin-top: 4px;">' + ackNote(event) + '</div>' +
               '</div>';
           return li;
        }

        function ackNote(event) {
           return event.alert_ack_at
               ? 'Acknowledged by ' + esc(event.alert_ack_by || 'staff') + ' at ' + esc(formatTime(event.alert_ack_at))
               : '';
        }

        function createCard(event) {
           if (event.alert_key) return createAlertCard(event);
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

           // No plate text: the plate camera saw a vehicle without a readable plate (it sends
           // its photos), or never read the vehicle at all (a vehicle counted by another camera:
           // no plate photos).
           const plate = event.lpr_plate_string ||
               (isTrue(event.has_lpr_img) || isTrue(event.has_context_img) ? 'NO PLATE' : 'NO PLATE READ');
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

        // ---- Lane alerts (such as a broken arm). Each alert that is on and not acknowledged
        // is a pulsing red card pinned at the top of the vehicle column, with Acknowledge, and
        // sounds an alarm every 15 seconds; a new one also raises a desktop notification
        // (clicking it brings the monitor forward) and flashes the tab's title. Acknowledge
        // (staff have seen it and called for repair) stops all of it on every screen.
        const alertList = document.getElementById('vehicle-alert-list');
        let activeAlerts = [];

        // The sound is made in the page: three rising two-tone beeps, about 2 seconds, as a
        // WAV clip played like any other audio. A browser plays it only after someone has
        // clicked or pressed a key on the page, so the page asks for a click until then.
        let alarmUrl = null;
        let soundAllowed = false; // a play has worked on this page
        function alarmClip() {
           if (alarmUrl) return alarmUrl;
           const rate = 22050;
           const seconds = 2.2;
           const n = Math.floor(rate * seconds);
           const buf = new ArrayBuffer(44 + n * 2);
           const v = new DataView(buf);
           function str(o, t) { for (let i = 0; i < t.length; i++) v.setUint8(o + i, t.charCodeAt(i)); }
           str(0, 'RIFF'); v.setUint32(4, 36 + n * 2, true); str(8, 'WAVE');
           str(12, 'fmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
           v.setUint32(24, rate, true); v.setUint32(28, rate * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true);
           str(36, 'data'); v.setUint32(40, n * 2, true);
           for (let i = 0; i < n; i++) {
              const t = i / rate;
              const inBeep = t % 0.7; // each beep pair: 880 Hz for 0.22 s, then 1320 Hz for 0.22 s
              let sample = 0;
              if (t < 2.1) {
                 const tone = inBeep < 0.22 ? 880 : (inBeep >= 0.25 && inBeep < 0.47 ? 1320 : 0);
                 if (tone) {
                    const into = inBeep < 0.22 ? inBeep : inBeep - 0.25;
                    const env = Math.min(1, into / 0.01, (0.22 - into) / 0.01); // no clicks
                    sample = (Math.sin(2 * Math.PI * tone * t) >= 0 ? 1 : -1) * 0.4 * env;
                 }
              }
              v.setInt16(44 + i * 2, Math.round(sample * 32767), true);
           }
           alarmUrl = URL.createObjectURL(new Blob([buf], { type: 'audio/wav' }));
           return alarmUrl;
        }

        // Plays the alarm; resolves to '' when it played, or the browser's reason when not
        function playAlarm() {
           try {
              const clip = new Audio(alarmClip());
              clip.volume = 1;
              return clip.play().then(function() {
                 if (!soundAllowed) { soundAllowed = true; renderAlerts(); showSoundState(''); }
                 return '';
              }, function(err) {
                 return (err && (err.name + ': ' + err.message)) || 'refused';
              });
           } catch (err) {
              return Promise.resolve(String(err));
           }
        }

        // A click or key press lets the page play sound: play at once if an alert is showing
        function unlockAudio() {
           if (!soundAllowed && activeAlerts.length > 0) playAlarm();
        }
        document.addEventListener('click', unlockAudio);
        document.addEventListener('keydown', unlockAudio);

        // The button by the column's heading: "Turn on alert sound" until a sound has played
        // on this page, then "Test sound". Its click also asks to allow desktop notifications.
        const testSound = document.getElementById('vehicle-alert-test-sound');
        function showSoundState(problem) {
           if (!testSound) return;
           testSound.title = problem
               ? 'The browser did not play it: ' + problem
               : 'Play the alert sound, to check the speakers';
           testSound.textContent = problem
               ? '\u{1F507} No sound: ' + problem
               : (soundAllowed ? '\u{1F514} Test sound' : '\u{1F514} Turn on alert sound');
           testSound.style.background = soundAllowed || problem ? '#fff' : '#fde68a';
           testSound.style.color = problem ? '#991b1b' : '#1f2937'; // the theme makes button text white
        }
        if (testSound) {
           showSoundState('');
           testSound.addEventListener('click', function(e) {
              e.stopPropagation(); // one play, not a second from unlockAudio
              if ('Notification' in window && Notification.permission === 'default') {
                 Notification.requestPermission();
              }
              playAlarm().then(showSoundState);
           });
        }

        // A desktop notification for a new alert: it shows even when the browser is behind
        // other windows, and clicking it brings the monitor forward.
        function notify(a) {
           try { window.focus(); } catch (e) { /* most browsers refuse; the notification is the way */ }
           if (!('Notification' in window) || Notification.permission !== 'granted') return;
           try {
              const n = new Notification('⚠ ' + (a.gate_identifier || 'Vehicle gate') + ': ' + (a.alert_text || a.alert_key), {
                 body: 'Since ' + formatTime(a.event_timestamp) + '. Open the live monitor to acknowledge.',
                 tag: 'fsbhoa-vehicle-alert-' + a.vehicle_log_id,
                 requireInteraction: true
              });
              n.onclick = function() { window.focus(); n.close(); };
           } catch (e) {
              console.warn('Vehicle alert notification failed:', e);
           }
        }

        // The tab's title flashes while an alert is on
        const pageTitle = document.title;
        let titleFlip = false;
        setInterval(function() {
           if (activeAlerts.length === 0) {
              if (document.title !== pageTitle) document.title = pageTitle;
              return;
           }
           titleFlip = !titleFlip;
           const a = activeAlerts[0];
           document.title = titleFlip ? '⚠ ' + (a.alert_text || a.alert_key) + ' - ' + a.gate_identifier : pageTitle;
        }, 1000);

        function renderAlerts() {
           if (!alertList) return;
           alertList.innerHTML = '';
           activeAlerts.forEach(function(a) {
              const card = createAlertCard(Object.assign({}, a, { alert_active: 1 }));
              card.style.animation = 'fsbhoa-alert-pulse 1s ease-in-out infinite alternate';
              card.style.borderRadius = '8px';
              card.style.marginBottom = '8px';
              const box = card.lastChild;
              box.insertAdjacentHTML('beforeend',
                 '<div style="margin-top: 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">' +
                   '<button type="button" data-ack="' + parseInt(a.vehicle_log_id, 10) + '" style="background: #b91c1c; color: #fff; border: 0; border-radius: 6px; padding: 6px 14px; font-weight: bold; cursor: pointer;" title="Stops the alarm on every screen">Acknowledge</button>' +
                   (soundAllowed ? '' : '<span style="font-size: 12px; color: #991b1b;">Click anywhere on this page to turn on the alarm sound.</span>') +
                 '</div>');
              alertList.appendChild(card);
           });
        }

        if (alertList) {
           const style = document.createElement('style');
           style.textContent = '@keyframes fsbhoa-alert-pulse { from { background-color: #fee2e2; } to { background-color: #fca5a5; } }';
           document.head.appendChild(style);
           alertList.addEventListener('click', async function(e) {
              const id = e.target && e.target.getAttribute('data-ack');
              if (!id) return;
              e.target.disabled = true;
              try {
                 const resp = await fetch('/wp-json/fsbhoa/v1/vehicle-alerts/' + encodeURIComponent(id) + '/ack', {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': nonce }
                 });
                 if (!resp.ok) throw new Error('HTTP ' + resp.status);
                 const ack = await resp.json();
                 activeAlerts = activeAlerts.filter(function(a) { return String(a.vehicle_log_id) !== id; });
                 renderAlerts();
                 const note = vehicleEventList.querySelector('li[data-log-id="' + parseInt(id, 10) + '"] .fsbhoa-alert-ack');
                 if (note) note.innerHTML = ackNote(ack);
              } catch (err) {
                 e.target.disabled = false;
                 console.warn('Vehicle alert acknowledgment failed:', err);
              }
           });
        }

        async function fetchAlerts() {
           if (!alertList) return;
           try {
              const resp = await fetch('/wp-json/fsbhoa/v1/vehicle-alerts', { headers: { 'X-WP-Nonce': nonce } });
              if (!resp.ok) return;
              const alerts = await resp.json();
              if (!Array.isArray(alerts)) return;
              const changed = JSON.stringify(alerts.map(function(a) { return a.vehicle_log_id; })) !==
                              JSON.stringify(activeAlerts.map(function(a) { return a.vehicle_log_id; }));
              activeAlerts = alerts;
              if (changed) renderAlerts();
           } catch (err) {
              console.warn('Vehicle alert fetch error:', err);
           }
        }

        // The sound repeats every 15 seconds while an alert is on and not acknowledged
        setInterval(function() {
           if (activeAlerts.length > 0) playAlarm();
        }, 15000);

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
        fetchAlerts().then(function() {
           if (activeAlerts.length > 0) playAlarm();
        });
        setInterval(function() {
           const before = {};
           activeAlerts.forEach(function(a) { before[a.vehicle_log_id] = true; });
           fetchAlerts().then(function() {
              // A new alert sounds at once, notifies, and brings the column into view
              const fresh = activeAlerts.filter(function(a) { return !before[a.vehicle_log_id]; });
              if (fresh.length === 0) return;
              playAlarm();
              fresh.forEach(notify);
              if (alertList) alertList.scrollIntoView({ block: 'start', behavior: 'smooth' });
           });
        }, 3000);
    });
})();

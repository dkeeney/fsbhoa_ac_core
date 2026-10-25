// Recorded video of a vehicle lane's cameras, for the live monitor's vehicle cards and the
// Vehicle Log report's rows: a Video button that opens a player with the lane's cameras from
// 15 s before the row's time, a minute at a time, played as it arrives (the vehicle hardware
// plugin's route fetches it from its recorder). One camera at a time; buttons switch camera,
// move 30 s earlier or later, or ask for the sharper picture.
//
// Offered by the fsbhoa_monitor_vehicle_video filter (Fsbhoa_Vehicle_Log_REST_API::monitor_video()),
// passed in as fsbhoa_vehicle_video = { url, lanes: { <lane>: [{ id, label }] }, nonce }.
(function() {
    const video = window.fsbhoa_vehicle_video || {};
    const lanes = video.lanes || {};
    const before = 15;
    const length = 60;
    let playing = null; // { gate, at, camera, shift, quality }
    let modal = null;

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    // "3:07:42 PM" from a Date
    function clock(d) {
        const h = d.getHours();
        return ((h % 12) || 12) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + ' ' + (h < 12 ? 'AM' : 'PM');
    }

    // The player, made the first time it is needed
    function player() {
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'fsbhoa-vehicle-video-modal';
        modal.style.cssText = 'display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 99999; justify-content: center; align-items: center; padding: 20px;';
        modal.innerHTML =
            '<div style="width: min(1280px, 92vw); text-align: center; color: #fff;">' +
              '<div data-part="title" style="font-size: 16px; font-weight: 600; margin-bottom: 8px;"></div>' +
              '<div data-part="cameras" style="display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin-bottom: 8px;"></div>' +
              '<video data-part="video" controls autoplay muted playsinline style="width: 100%; max-height: 72vh; background: #000; border-radius: 8px;"></video>' +
              '<div data-part="status" style="font-size: 13px; color: #cbd5e1; margin-top: 6px; min-height: 18px;"></div>' +
              '<div style="margin-top: 8px; display: flex; gap: 6px; justify-content: center; flex-wrap: wrap;">' +
                '<button type="button" class="button" data-video-shift="-30">&#9664; 30 s earlier</button>' +
                '<button type="button" class="button" data-video-shift="30">30 s later &#9654;</button>' +
                '<button type="button" class="button" data-part="quality">Sharper</button>' +
                '<button type="button" class="button" data-part="close">Close</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modal);
        const v = part('video');
        v.addEventListener('playing', function() { part('status').textContent = ''; });
        v.addEventListener('error', function() {
            if (!playing || !v.getAttribute('src')) return;
            // Ask again for the server's reason (it answers at once when there is no video)
            const ask = new AbortController();
            fetch(url(), { signal: ask.signal }).then(function(r) {
                if (r.ok) { ask.abort(); return ''; } // video after all: don't download it twice
                return r.text();
            }).then(function(reason) {
                part('status').textContent = 'No video: ' + (reason || 'the browser could not play it');
            }).catch(function() { part('status').textContent = 'No video: the server did not answer'; });
        });
        modal.addEventListener('click', function(e) {
            const t = e.target;
            if (t === modal || t.dataset.part === 'close') {
                stop();
                modal.style.display = 'none';
                playing = null;
            } else if (t.dataset.videoCamera !== undefined) {
                playing.camera = (lanes[playing.gate] || [])[parseInt(t.dataset.videoCamera, 10)] || playing.camera;
                play();
            } else if (t.dataset.videoShift !== undefined) {
                playing.shift += parseInt(t.dataset.videoShift, 10);
                play();
            } else if (t.dataset.part === 'quality') {
                playing.quality = playing.quality === 'sharp' ? '' : 'sharp';
                play();
            }
        });
        return modal;
    }

    function part(name) { return modal.querySelector('[data-part="' + name + '"]'); }

    function url() {
        const params = new URLSearchParams({
            camera: playing.camera.id,
            at: playing.at.substring(0, 19),
            before: String(before - playing.shift),
            length: String(length),
            quality: playing.quality,
            _wpnonce: video.nonce || ''
        });
        return video.url + (video.url.indexOf('?') < 0 ? '?' : '&') + params.toString();
    }

    function play() {
        const cameras = lanes[playing.gate] || [];
        const start = new Date(new Date(playing.at.substring(0, 19).replace(' ', 'T')).getTime() + (playing.shift - before) * 1000);
        part('title').textContent = playing.gate + ' • ' + playing.camera.label + ' • ' +
            playing.at.substring(0, 10) + ' from ' + clock(start) + (playing.quality === 'sharp' ? ' • sharper' : '');
        part('cameras').innerHTML = cameras.map(function(c, i) {
            const on = c.id === playing.camera.id;
            return '<button type="button" class="button" data-video-camera="' + i + '" style="' +
                (on ? 'background: #1d4ed8; color: #fff; border-color: #1d4ed8; font-weight: 600;' : '') + '">' + esc(c.label) + '</button>';
        }).join('');
        part('quality').textContent = playing.quality === 'sharp' ? 'Normal size' : 'Sharper';
        part('status').textContent = 'Loading from the recorder… (the video plays at normal speed as it arrives)';
        const v = part('video');
        v.src = url();
        v.play().catch(function() { /* autoplay refused: the controls are there */ });
    }

    function stop() {
        const v = part('video');
        v.pause();
        v.removeAttribute('src');
        v.load(); // closes the connection, which stops the server's conversion
    }

    // Any Video button on the page
    document.addEventListener('click', function(e) {
        const b = e.target && e.target.closest ? e.target.closest('[data-video-gate]') : null;
        if (!b) return;
        const cameras = lanes[b.dataset.videoGate] || [];
        if (!cameras.length) return;
        e.preventDefault();
        player().style.display = 'flex';
        playing = { gate: b.dataset.videoGate, at: b.dataset.videoAt, camera: cameras[0], shift: 0, quality: '' };
        play();
    });

    window.fsbhoaVehicleVideo = {
        // The Video button for a lane and a local time "YYYY-MM-DD HH:MM:SS[.mmm]"; '' when the
        // lane has no cameras with recordings
        button: function(gate, at) {
            if (!video.url || !lanes[gate] || !at) return '';
            return '<button type="button" data-video-gate="' + esc(gate) + '" data-video-at="' + esc(at) + '"' +
                ' style="margin-top: 6px; font-size: 12px; padding: 2px 10px; border-radius: 4px; border: 1px solid #94a3b8; background: #fff; color: #1f2937; cursor: pointer;"' +
                ' title="Play the lane\'s cameras from just before this moment">&#9654; Video</button>';
        }
    };
})();

(function () {
    'use strict';

    var config = window.FaceIdKioskConfig;
    if (!config) return;

    var video = document.getElementById('kiosk-video');
    var statusEl = document.getElementById('kiosk-status');
    var scanning = false;
    var modelsLoaded = false;
    var lastStatusText = '';
    var statusClearTimer = null;

    function setStatus(text, className) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.className = 'kiosk-status' + (className ? ' ' + className : '');
        lastStatusText = text;
    }

    function clearStatusLater() {
        if (statusClearTimer) clearTimeout(statusClearTimer);
        statusClearTimer = setTimeout(function () {
            if (statusEl && statusEl.textContent === lastStatusText) {
                setStatus(config.labels.ready || 'Ready');
            }
        }, 5000);
    }

    function formatTime(isoString) {
        if (!isoString) return '';
        var d = new Date(isoString.replace(' ', 'T'));
        if (isNaN(d.getTime())) return isoString;
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function loadModels() {
        return Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(config.modelsUrl),
            faceapi.nets.faceLandmark68Net.loadFromUri(config.modelsUrl),
            faceapi.nets.faceRecognitionNet.loadFromUri(config.modelsUrl)
        ]).then(function () {
            modelsLoaded = true;
        });
    }

    function startCamera() {
        return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
            .then(function (stream) {
                if (video) {
                    video.srcObject = stream;
                    return video.play();
                }
            });
    }

    function scanOnce() {
        if (!modelsLoaded || !video || scanning) return Promise.resolve();
        scanning = true;

        return faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks()
            .withFaceDescriptor()
            .then(function (result) {
                if (!result) return null;

                var descriptor = Array.from(result.descriptor);
                return fetch(config.punchUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ descriptor: descriptor })
                }).then(function (response) {
                    return response.json();
                });
            })
            .then(function (data) {
                if (!data || !data.recognized) return;

                if (data.debounced) return;

                var name = data.employee_name || '';
                var time = formatTime(data.recorded_at);
                if (data.event_type === 'clock_in') {
                    setStatus(
                        (config.labels.welcome || 'Welcome') + ', ' + name + ' — ' +
                        (config.labels.clockIn || 'Clocked in') + (time ? ' ' + time : ''),
                        'kiosk-status--in'
                    );
                } else if (data.event_type === 'clock_out') {
                    setStatus(
                        (config.labels.welcome || 'Welcome') + ', ' + name + ' — ' +
                        (config.labels.clockOut || 'Clocked out') + (time ? ' ' + time : ''),
                        'kiosk-status--out'
                    );
                }
                clearStatusLater();
            })
            .catch(function () {
                // Silent — unrecognized or network errors are ignored on kiosk
            })
            .finally(function () {
                scanning = false;
            });
    }

    function startScanLoop() {
        setInterval(function () {
            scanOnce();
        }, config.scanIntervalMs || 2000);
    }

    setStatus(config.labels.starting || 'Starting camera…');

    loadModels()
        .then(startCamera)
        .then(function () {
            setStatus(config.labels.ready || 'Ready');
            startScanLoop();
        })
        .catch(function (err) {
            setStatus('Camera unavailable: ' + (err && err.message ? err.message : 'permission denied'));
        });
})();

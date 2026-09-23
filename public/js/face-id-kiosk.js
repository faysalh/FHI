(function () {
    'use strict';

    var config = window.FaceIdKioskConfig;
    if (!config) return;

    if (typeof faceapi === 'undefined') {
        var statusEl = document.getElementById('kiosk-status');
        if (statusEl) {
            statusEl.textContent = 'face-api.js failed to load.';
        }
        return;
    }

    var video = document.getElementById('kiosk-video');
    var statusEl = document.getElementById('kiosk-status');
    var debugEl = document.getElementById('kiosk-debug');
    var detector = null;
    var scanning = false;
    var lastStatusText = '';
    var statusClearTimer = null;
    var scanTimer = null;
    var locationWatchId = null;
    var lastLocation = null;
    var lastLocationAt = 0;
    var debugMode = /[?&]debug=1(?:&|$)/.test(window.location.search);

    function setStatus(text, className) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.className = 'kiosk-status' + (className ? ' ' + className : '');
        lastStatusText = text;
    }

    function setDebug(text) {
        if (!debugEl || !debugMode) return;
        debugEl.textContent = text;
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

    function locationErrorMessage(error) {
        if (!error) {
            return config.labels.locationDenied || 'Location permission denied';
        }
        if (error.code === 1) {
            return config.labels.locationDenied || 'Location permission denied';
        }
        if (error.code === 2) {
            return config.labels.locationUnavailable || 'Location unavailable';
        }
        if (error.code === 3) {
            return config.labels.locationTimeout || 'Location request timed out';
        }
        return config.labels.locationDenied || 'Location permission denied';
    }

    function storePosition(position) {
        lastLocation = {
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            location_accuracy: position.coords.accuracy
        };
        lastLocationAt = Date.now();
        if (debugMode) {
            setDebug(
                'Location ' + lastLocation.latitude.toFixed(5) + ', ' + lastLocation.longitude.toFixed(5) +
                (lastLocation.location_accuracy ? ' ±' + Math.round(lastLocation.location_accuracy) + 'm' : '')
            );
        }
    }

    function startLocationWatch() {
        return new Promise(function (resolve, reject) {
            if (!navigator.geolocation) {
                reject(new Error(config.labels.locationUnsupported || 'Geolocation is not supported on this device.'));
                return;
            }

            setStatus(config.labels.requestingLocation || 'Allow location access…');

            navigator.geolocation.getCurrentPosition(
                function (position) {
                    storePosition(position);
                    if (locationWatchId !== null) {
                        navigator.geolocation.clearWatch(locationWatchId);
                    }
                    locationWatchId = navigator.geolocation.watchPosition(
                        storePosition,
                        function () {},
                        {
                            enableHighAccuracy: true,
                            maximumAge: 15000,
                            timeout: 20000
                        }
                    );
                    resolve(lastLocation);
                },
                function (error) {
                    reject(new Error(locationErrorMessage(error)));
                },
                {
                    enableHighAccuracy: true,
                    maximumAge: 0,
                    timeout: 20000
                }
            );
        });
    }

    function currentLocationPayload() {
        if (!lastLocation) {
            return null;
        }
        if (Date.now() - lastLocationAt > 60000) {
            return null;
        }
        return {
            latitude: lastLocation.latitude,
            longitude: lastLocation.longitude,
            location_accuracy: lastLocation.location_accuracy
        };
    }

    function scheduleNextScan(delayMs) {
        if (scanTimer) clearTimeout(scanTimer);
        scanTimer = setTimeout(function () {
            scanOnce();
        }, delayMs);
    }

    function scanOnce() {
        if (!detector || scanning) {
            scheduleNextScan(config.scanIntervalIdleMs || 2000);
            return;
        }

        var locationPayload = currentLocationPayload();
        if (!locationPayload) {
            setStatus(config.labels.waitingLocation || 'Waiting for location…');
            scheduleNextScan(config.scanIntervalIdleMs || 2000);
            return;
        }

        var det = detector.getBestDetection();
        if (!det || !det.descriptor) {
            if (debugMode) setDebug('No face in frame');
            scheduleNextScan(config.scanIntervalIdleMs || 2000);
            return;
        }

        scanning = true;
        var descriptor = Array.from(det.descriptor);

        fetch(config.punchUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                descriptor: descriptor,
                latitude: locationPayload.latitude,
                longitude: locationPayload.longitude,
                location_accuracy: locationPayload.location_accuracy
            })
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        var message = (data && data.message) ? data.message : 'Punch failed';
                        if (data && data.errors) {
                            message = Object.values(data.errors).flat().join(' ');
                        }
                        throw new Error(message);
                    }
                    return data;
                });
            })
            .then(function (data) {
                if (!data) return;

                if (debugMode) {
                    if (data.recognized) {
                        setDebug('Recognized: ' + (data.employee_name || data.employee_id));
                    } else {
                        setDebug('Face seen — not recognized');
                    }
                }

                if (!data.recognized || data.debounced) return;

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
            .catch(function (err) {
                if (debugMode) {
                    setDebug('Punch request failed: ' + (err && err.message ? err.message : 'unknown'));
                }
            })
            .finally(function () {
                scanning = false;
                var nextDelay = (det && det.descriptor)
                    ? (config.scanIntervalActiveMs || 500)
                    : (config.scanIntervalIdleMs || 2000);
                scheduleNextScan(nextDelay);
            });
    }

    setStatus(config.labels.starting || 'Starting…');

    detector = new FaceIdDetector({
        modelsUrl: config.modelsUrl,
        video: video,
        overlay: null,
        onStatus: function (text) {
            if (debugMode) setDebug(text);
        },
        onReadyChange: function () {},
        onDebug: function (info) {
            if (debugMode) {
                setDebug('Score ' + (info.score || 0).toFixed(2) + ' | ' + (info.detectionCount || 0) + ' face(s)');
            }
        }
    });

    startLocationWatch()
        .then(function () {
            setStatus(config.labels.requestingCamera || 'Allow camera access…');
            return detector.start();
        })
        .then(function () {
            setStatus(config.labels.ready || 'Ready');
            scheduleNextScan(config.scanIntervalIdleMs || 2000);
        })
        .catch(function (err) {
            setStatus((err && err.message) ? err.message : 'Setup failed');
        });

    window.addEventListener('beforeunload', function () {
        if (locationWatchId !== null) {
            navigator.geolocation.clearWatch(locationWatchId);
        }
        if (detector) detector.stop();
        if (scanTimer) clearTimeout(scanTimer);
    });
})();

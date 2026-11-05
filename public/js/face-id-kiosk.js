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
    var locationDenied = false;
    var cameraStarted = false;
    var holdingResult = false;
    var latestFaceStatus = '';
    var debugMode = /[?&]debug=1(?:&|$)/.test(window.location.search);
    var LOCATION_FRESH_MS = 120000;

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
        holdingResult = true;
        statusClearTimer = setTimeout(function () {
            holdingResult = false;
            if (statusEl && statusEl.textContent === lastStatusText) {
                setStatus(latestFaceStatus || config.labels.ready || 'Ready');
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

    function accuracyMeters(fix) {
        var value = fix && typeof fix.location_accuracy === 'number' ? fix.location_accuracy : null;
        return value !== null && isFinite(value) ? value : null;
    }

    function storePosition(position) {
        if (!position || !position.coords) return;
        var next = {
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            location_accuracy: position.coords.accuracy
        };
        if (lastLocation && Date.now() - lastLocationAt < LOCATION_FRESH_MS) {
            var nextAcc = accuracyMeters(next);
            var prevAcc = accuracyMeters(lastLocation);
            if (nextAcc === null && prevAcc !== null) return;
            if (nextAcc !== null && prevAcc !== null && nextAcc > prevAcc + 50) return;
        }
        lastLocation = next;
        lastLocationAt = Date.now();
        if (debugMode) {
            setDebug(
                'Location ' + lastLocation.latitude.toFixed(5) + ', ' + lastLocation.longitude.toFixed(5) +
                (lastLocation.location_accuracy ? ' ±' + Math.round(lastLocation.location_accuracy) + 'm' : '')
            );
        }
    }

    function markLocationDenied(error) {
        locationDenied = true;
        if (locationWatchId !== null && navigator.geolocation) {
            navigator.geolocation.clearWatch(locationWatchId);
            locationWatchId = null;
        }
        setStatus(locationErrorMessage(error));
    }

    function ensureLocationWatch() {
        if (locationDenied || locationWatchId !== null || !navigator.geolocation) return;
        locationWatchId = navigator.geolocation.watchPosition(
            storePosition,
            function (error) {
                if (error && error.code === 1) {
                    markLocationDenied(error);
                }
            },
            {
                enableHighAccuracy: true,
                maximumAge: 60000,
                timeout: 30000
            }
        );
    }

    function requestQuickLocation() {
        if (locationDenied || !navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(
            storePosition,
            function (error) {
                if (error && error.code === 1) {
                    markLocationDenied(error);
                    return;
                }
                if (lastLocation) return;
                navigator.geolocation.getCurrentPosition(
                    storePosition,
                    function (secondError) {
                        if (secondError && secondError.code === 1) {
                            markLocationDenied(secondError);
                        }
                    },
                    {
                        enableHighAccuracy: false,
                        maximumAge: 180000,
                        timeout: 15000
                    }
                );
            },
            {
                enableHighAccuracy: false,
                maximumAge: 120000,
                timeout: 12000
            }
        );
    }

    function beginLocation() {
        if (!navigator.geolocation) {
            setStatus(config.labels.locationUnsupported || 'Geolocation is not supported on this device.');
            return;
        }
        setStatus(config.labels.requestingLocation || 'Allow location access…');
        ensureLocationWatch();
        requestQuickLocation();
    }

    function currentLocationPayload() {
        if (!lastLocation) {
            return null;
        }
        if (Date.now() - lastLocationAt > LOCATION_FRESH_MS) {
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
            if (!locationDenied) {
                setStatus(config.labels.waitingLocation || 'Finding location… you can stand at the camera.');
            }
            scheduleNextScan(1000);
            return;
        }

        if (!detector.isReady()) {
            if (debugMode) setDebug('Waiting for a stable face above 70% quality');
            scheduleNextScan(config.scanIntervalIdleMs || 2000);
            return;
        }

        scanning = true;
        setStatus(config.labels.verifying || 'Face locked — verifying 3 samples…');

        detector.captureFreshDescriptors(3, 250, 8000, function (captured, total) {
            setStatus('Verifying face ' + captured + '/' + total + '…');
        })
            .then(function (descriptors) {
                return fetch(config.punchUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        descriptors: descriptors,
                        latitude: locationPayload.latitude,
                        longitude: locationPayload.longitude,
                        location_accuracy: locationPayload.location_accuracy
                    })
                });
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
                        setDebug(
                            'Recognized: ' + (data.employee_name || data.employee_id) +
                            ' | identity ' + Math.round((data.confidence || 0) * 100) + '%'
                        );
                    } else {
                        setDebug('Face samples did not reach 70% identity confidence');
                    }
                }

                if (!data.recognized) {
                    setStatus(config.labels.notRecognized || 'Not recognized above 70% — look at the camera and try again');
                    clearStatusLater();
                    return;
                }
                if (data.debounced) {
                    setStatus(config.labels.alreadyRecorded || 'Attendance already recorded — please step away');
                    clearStatusLater();
                    return;
                }

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
                setStatus((err && err.message) ? err.message : 'Face verification failed');
                clearStatusLater();
                if (debugMode) {
                    setDebug('Punch request failed: ' + (err && err.message ? err.message : 'unknown'));
                }
            })
            .finally(function () {
                scanning = false;
                scheduleNextScan(config.scanIntervalActiveMs || 500);
            });
    }

    function startCamera() {
        if (cameraStarted) return;
        cameraStarted = true;
        if (!currentLocationPayload() && !locationDenied) {
            setStatus(config.labels.requestingCamera || 'Allow camera access…');
        }
        detector.start()
            .then(function () {
                if (!currentLocationPayload() && !locationDenied) {
                    setStatus(config.labels.waitingLocation || 'Finding location… you can stand at the camera.');
                } else if (!holdingResult) {
                    setStatus(latestFaceStatus || config.labels.ready || 'Ready');
                }
                scheduleNextScan(config.scanIntervalIdleMs || 2000);
            })
            .catch(function (err) {
                setStatus((err && err.message) ? err.message : 'Camera failed');
            });
    }

    setStatus(config.labels.starting || 'Starting…');

    detector = new FaceIdDetector({
        modelsUrl: config.modelsUrl,
        video: video,
        overlay: null,
        onStatus: function (text) {
            latestFaceStatus = text || '';
            if (debugMode) setDebug(text);
            if (scanning || holdingResult || locationDenied || !currentLocationPayload() || !text) return;
            setStatus(text);
        },
        onReadyChange: function () {},
        onDebug: function (info) {
            if (debugMode) {
                setDebug('Score ' + (info.score || 0).toFixed(2) + ' | ' + (info.detectionCount || 0) + ' face(s)');
            }
        }
    });

    beginLocation();
    setTimeout(startCamera, 400);

    window.addEventListener('beforeunload', function () {
        if (locationWatchId !== null) {
            navigator.geolocation.clearWatch(locationWatchId);
        }
        if (detector) detector.stop();
        if (scanTimer) clearTimeout(scanTimer);
    });
})();

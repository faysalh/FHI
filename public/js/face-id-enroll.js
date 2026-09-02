(function () {
    'use strict';

    var config = window.FaceIdEnrollConfig;
    if (!config) {
        console.error('FaceIdEnrollConfig is missing.');
        return;
    }

    if (typeof faceapi === 'undefined') {
        var earlyStatus = document.getElementById('face-enroll-status');
        if (earlyStatus) {
            earlyStatus.textContent = 'face-api.js failed to load. Contact your administrator.';
            earlyStatus.classList.add('face-enroll-status--error');
        }
        return;
    }

    var modal = document.getElementById('face-enroll-modal');
    var video = document.getElementById('face-enroll-video');
    var overlay = document.getElementById('face-enroll-overlay');
    var statusEl = document.getElementById('face-enroll-status');
    var captureBtn = document.getElementById('face-enroll-capture');
    var startBtn = document.getElementById('face-enroll-start');
    var employeeNameEl = document.getElementById('face-enroll-employee-name');
    var debugEl = document.getElementById('face-enroll-debug');
    var currentEmployeeId = null;
    var detector = null;
    var capturing = false;
    var autoCaptureEnabled = config.autoCapture !== false;
    var stableReadySince = 0;
    var autoCaptureTimer = null;
    var lastApiResponse = '';

    function setStatus(text, tone) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.classList.remove('face-enroll-status--ready', 'face-enroll-status--warn', 'face-enroll-status--error');
        if (tone) statusEl.classList.add('face-enroll-status--' + tone);
    }

    function setDebug(lines) {
        if (!debugEl) return;
        debugEl.innerHTML = lines.map(function (line) {
            return '<div>' + line + '</div>';
        }).join('');
    }

    function showModal() {
        if (!modal) return;
        modal.hidden = false;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function hideModal() {
        if (!modal) return;
        modal.hidden = true;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function clearAutoCapture() {
        if (autoCaptureTimer) {
            clearTimeout(autoCaptureTimer);
            autoCaptureTimer = null;
        }
        stableReadySince = 0;
    }

    function stopDetector() {
        clearAutoCapture();
        if (detector) {
            detector.stop();
            detector = null;
        }
    }

    function closeModal() {
        stopDetector();
        currentEmployeeId = null;
        capturing = false;
        hideModal();
        if (captureBtn) captureBtn.disabled = true;
        if (startBtn) startBtn.hidden = true;
    }

    function onReadyChange(ready) {
        if (captureBtn) {
            captureBtn.disabled = !ready || capturing;
        }
        if (!ready || capturing) {
            clearAutoCapture();
            return;
        }
        if (!autoCaptureEnabled) {
            setStatus('Ready — tap Capture', 'ready');
            return;
        }
        if (!stableReadySince) {
            stableReadySince = Date.now();
            setStatus('Ready — hold still for auto-capture…', 'ready');
        }
        var elapsed = Date.now() - stableReadySince;
        if (elapsed >= 1500 && !autoCaptureTimer) {
            autoCaptureTimer = setTimeout(function () {
                autoCaptureTimer = null;
                captureFace(true);
            }, 100);
        }
    }

    function checkModelsReachable() {
        var url = (config.modelsUrl || '').replace(/\/$/, '') + '/tiny_face_detector_model-weights_manifest.json';
        return fetch(url, { method: 'GET', cache: 'no-store' })
            .then(function (r) { return r.ok ? 'OK (' + r.status + ')' : 'HTTP ' + r.status; })
            .catch(function (e) { return 'Failed: ' + (e.message || 'network'); });
    }

    function startEnrollment() {
        stopDetector();
        if (captureBtn) captureBtn.disabled = true;
        if (startBtn) startBtn.hidden = true;
        stableReadySince = 0;

        detector = new FaceIdDetector({
            modelsUrl: config.modelsUrl,
            video: video,
            overlay: overlay,
            onStatus: setStatus,
            onReadyChange: onReadyChange,
            onDebug: function (info) {
                checkModelsReachable().then(function (modelsCheck) {
                    setDebug([
                        'Models URL: ' + config.modelsUrl,
                        'Models manifest: ' + modelsCheck,
                        'Video: ' + (info.videoWidth || 0) + 'x' + (info.videoHeight || 0) + ' @ ~' + (info.fps || 0) + ' fps',
                        'Score: ' + (info.score ? info.score.toFixed(3) : '0') + ' | Brightness: ' + (info.brightness || 0),
                        'Detections: ' + (info.detectionCount || 0) + ' | Ready: ' + (info.ready ? 'yes' : 'no'),
                        'Last API: ' + (lastApiResponse || '—')
                    ]);
                });
            }
        });

        detector.start().catch(function (err) {
            var message = err && err.message ? err.message : 'unknown error';
            if (/failed to load|face-api|model/i.test(message)) {
                setStatus('Model load failed: ' + message, 'error');
            } else {
                setStatus('Camera unavailable: ' + message, 'error');
            }
            if (startBtn) {
                startBtn.hidden = false;
                startBtn.onclick = function () {
                    startEnrollment();
                };
            }
        });
    }

    function openModal(employeeId, employeeName) {
        currentEmployeeId = employeeId;
        if (employeeNameEl) employeeNameEl.textContent = employeeName;
        showModal();
        setStatus('Loading face models…');
        lastApiResponse = '';
        startEnrollment();
    }

    function captureSamples(count, delayMs) {
        var samples = [];
        var index = 0;

        function takeOne() {
            if (!detector || !detector.isReady()) {
                return Promise.reject(new Error('Face not ready'));
            }
            var descriptor = detector.captureDescriptor();
            if (!descriptor) {
                return Promise.reject(new Error('No descriptor captured'));
            }
            samples.push(descriptor);
            index += 1;
            if (index >= count) {
                return Promise.resolve(samples);
            }
            return new Promise(function (resolve) {
                setTimeout(resolve, delayMs);
            }).then(takeOne);
        }

        return takeOne();
    }

    function saveDescriptors(descriptors) {
        var url = config.saveUrlTemplate.replace('__ID__', String(currentEmployeeId));
        var body = descriptors.length === 1
            ? { descriptor: descriptors[0] }
            : { descriptors: descriptors };

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().then(function (data) {
                lastApiResponse = JSON.stringify(data);
                return { ok: response.ok, status: response.status, data: data };
            });
        });
    }

    function captureFace(isAuto) {
        if (!currentEmployeeId || capturing) return;
        if (!detector || !detector.isReady()) {
            setStatus('Face not ready — center yourself and try again.', 'warn');
            return;
        }

        capturing = true;
        clearAutoCapture();
        if (captureBtn) captureBtn.disabled = true;
        setStatus(isAuto ? 'Auto-capturing face samples…' : 'Capturing face samples…');

        captureSamples(3, 300)
            .then(function (samples) {
                setStatus('Saving enrollment…');
                return saveDescriptors(samples);
            })
            .then(function (result) {
                if (result.ok && result.data && result.data.ok) {
                    setStatus('Face enrolled successfully.', 'ready');
                    setTimeout(function () {
                        window.location.reload();
                    }, 800);
                    return;
                }
                var msg = (result.data && result.data.message) || 'Enrollment failed.';
                if (result.data && result.data.errors) {
                    var errKeys = Object.keys(result.data.errors);
                    if (errKeys.length) {
                        msg += ' ' + result.data.errors[errKeys[0]].join(' ');
                    }
                }
                setStatus(msg, 'error');
                capturing = false;
                if (captureBtn) captureBtn.disabled = !detector || !detector.isReady();
            })
            .catch(function (err) {
                setStatus('Error: ' + (err && err.message ? err.message : 'unknown'), 'error');
                capturing = false;
                if (captureBtn) captureBtn.disabled = !detector || !detector.isReady();
            });
    }

    document.querySelectorAll('[data-face-enroll]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-face-enroll');
            var name = btn.getAttribute('data-employee-name') || '';
            if (id) openModal(id, name);
        });
    });

    document.querySelectorAll('[data-face-enroll-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    if (captureBtn) {
        captureBtn.addEventListener('click', function () {
            captureFace(false);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && !modal.hidden) {
            closeModal();
        }
    });
})();

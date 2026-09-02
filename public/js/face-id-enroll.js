(function () {
    'use strict';

    var config = window.FaceIdEnrollConfig;
    if (!config) return;

    var modal = document.getElementById('face-enroll-modal');
    var video = document.getElementById('face-enroll-video');
    var statusEl = document.getElementById('face-enroll-status');
    var captureBtn = document.getElementById('face-enroll-capture');
    var employeeNameEl = document.getElementById('face-enroll-employee-name');
    var currentEmployeeId = null;
    var stream = null;
    var modelsLoaded = false;
    var modelsLoading = null;

    function setStatus(text) {
        if (statusEl) statusEl.textContent = text;
    }

    function loadModels() {
        if (modelsLoaded) return Promise.resolve();
        if (modelsLoading) return modelsLoading;
        modelsLoading = Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(config.modelsUrl),
            faceapi.nets.faceLandmark68Net.loadFromUri(config.modelsUrl),
            faceapi.nets.faceRecognitionNet.loadFromUri(config.modelsUrl)
        ]).then(function () {
            modelsLoaded = true;
        });
        return modelsLoading;
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }
        if (video) video.srcObject = null;
    }

    function closeModal() {
        stopCamera();
        currentEmployeeId = null;
        if (modal) modal.hidden = true;
        if (captureBtn) captureBtn.disabled = true;
    }

    function openModal(employeeId, employeeName) {
        currentEmployeeId = employeeId;
        if (employeeNameEl) employeeNameEl.textContent = employeeName;
        if (modal) modal.hidden = false;
        setStatus('Loading face models…');
        if (captureBtn) captureBtn.disabled = true;

        loadModels().then(function () {
            return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        }).then(function (mediaStream) {
            stream = mediaStream;
            if (video) {
                video.srcObject = mediaStream;
                return video.play();
            }
        }).then(function () {
            setStatus('Position your face in the frame, then capture.');
            if (captureBtn) captureBtn.disabled = false;
        }).catch(function (err) {
            setStatus('Camera unavailable: ' + (err && err.message ? err.message : 'permission denied'));
        });
    }

    function captureFace() {
        if (!currentEmployeeId || !video) return;
        if (captureBtn) captureBtn.disabled = true;
        setStatus('Detecting face…');

        faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks()
            .withFaceDescriptor()
            .then(function (result) {
                if (!result) {
                    setStatus('No face detected. Try again.');
                    if (captureBtn) captureBtn.disabled = false;
                    return;
                }

                var descriptor = Array.from(result.descriptor);
                var url = config.saveUrlTemplate.replace('__ID__', String(currentEmployeeId));

                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ descriptor: descriptor })
                }).then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                });
            })
            .then(function (result) {
                if (!result) return;
                if (result.ok && result.data && result.data.ok) {
                    setStatus('Face enrolled successfully.');
                    setTimeout(function () {
                        window.location.reload();
                    }, 800);
                    return;
                }
                setStatus((result.data && result.data.message) || 'Enrollment failed.');
                if (captureBtn) captureBtn.disabled = false;
            })
            .catch(function (err) {
                setStatus('Error: ' + (err && err.message ? err.message : 'unknown'));
                if (captureBtn) captureBtn.disabled = false;
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
        captureBtn.addEventListener('click', captureFace);
    }
})();

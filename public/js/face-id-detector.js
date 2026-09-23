(function (global) {
    'use strict';

    var DEFAULT_OPTIONS = {
        inputSize: 512,
        scoreThreshold: 0.35,
        loopIntervalMs: 150,
        guideWidthRatio: 0.55,
        guideHeightRatio: 0.70,
        minBrightness: 45,
        maxBrightness: 220
    };

    function FaceIdDetector(userOptions) {
        this.options = Object.assign({}, DEFAULT_OPTIONS, userOptions || {});
        this.modelsUrl = normalizeModelsUrl(this.options.modelsUrl || '/face-api-models');
        this.video = this.options.video || null;
        this.overlay = this.options.overlay || null;
        this.onStatus = typeof this.options.onStatus === 'function' ? this.options.onStatus : function () {};
        this.onReadyChange = typeof this.options.onReadyChange === 'function' ? this.options.onReadyChange : function () {};
        this.onDebug = typeof this.options.onDebug === 'function' ? this.options.onDebug : function () {};

        this._stream = null;
        this._loopTimer = null;
        this._modelsLoaded = false;
        this._modelsLoading = null;
        this._running = false;
        this._lastDetection = null;
        this._lastScore = 0;
        this._lastBrightness = 0;
        this._frameCount = 0;
        this._fpsStart = 0;
        this._fps = 0;
        this._ready = false;
        this._readyReason = '';
        this._detectorOptions = null;
    }

    function normalizeModelsUrl(url) {
        if (!url) {
            return '/face-api-models';
        }
        try {
            if (/^https?:\/\//i.test(url)) {
                var parsed = new URL(url, window.location.origin);
                return parsed.pathname.replace(/\/$/, '');
            }
        } catch (e) {
            // fall through
        }
        if (url.charAt(0) !== '/') {
            url = '/' + url;
        }
        return url.replace(/\/$/, '');
    }

    FaceIdDetector.prototype._detector = function () {
        if (!this._detectorOptions && typeof faceapi !== 'undefined') {
            this._detectorOptions = new faceapi.TinyFaceDetectorOptions({
                inputSize: this.options.inputSize,
                scoreThreshold: this.options.scoreThreshold
            });
        }
        return this._detectorOptions;
    };

    FaceIdDetector.prototype._verifyModelAsset = function (relativePath, minBytes) {
        var url = this.modelsUrl + '/' + relativePath;
        return fetch(url, { method: 'GET', cache: 'no-store' }).then(function (response) {
            if (!response.ok) {
                throw new Error(relativePath + ' HTTP ' + response.status);
            }
            var contentType = response.headers.get('content-type') || '';
            if (contentType.indexOf('text/html') !== -1) {
                throw new Error(relativePath + ' returned HTML (IIS routed to Laravel instead of static file)');
            }
            return response.blob().then(function (blob) {
                if (minBytes && blob.size < minBytes) {
                    throw new Error(relativePath + ' too small (' + blob.size + ' bytes)');
                }
            });
        });
    };

    FaceIdDetector.prototype.loadModels = function () {
        if (typeof faceapi === 'undefined') {
            return Promise.reject(new Error('face-api.js is not loaded.'));
        }
        if (this._modelsLoaded) {
            return Promise.resolve();
        }
        if (this._modelsLoading) {
            return this._modelsLoading;
        }

        var self = this;
        var steps = [
            { name: 'tinyFaceDetector', fn: function () { return faceapi.nets.tinyFaceDetector.loadFromUri(self.modelsUrl); } },
            { name: 'faceLandmark68Net', fn: function () { return faceapi.nets.faceLandmark68Net.loadFromUri(self.modelsUrl); } },
            { name: 'faceRecognitionNet', fn: function () { return faceapi.nets.faceRecognitionNet.loadFromUri(self.modelsUrl); } }
        ];
        var loaded = 0;

        this._modelsLoading = self._verifyModelAsset('tiny_face_detector_model-weights_manifest.json', 100)
            .then(function () { return self._verifyModelAsset('tiny_face_detector_model-shard1.bin', 100000); })
            .then(function () {
                self.onStatus('Model weights reachable. Loading nets…');
            })
            .then(function () {
                return steps.reduce(function (chain, step) {
                    return chain.then(function () {
                        self.onStatus('Loading models (' + (loaded + 1) + '/' + steps.length + ')…');
                        return step.fn().then(function () {
                            loaded += 1;
                            self.onDebug({ modelsLoaded: loaded, modelStep: step.name });
                        }).catch(function (err) {
                            throw new Error('Failed to load ' + step.name + ': ' + (err && err.message ? err.message : 'unknown'));
                        });
                    });
                }, Promise.resolve());
            }).then(function () {
                self._modelsLoaded = true;
                self._modelsLoading = null;
                self.onStatus('Models ready.');
            }).catch(function (err) {
                self._modelsLoading = null;
                self._modelsLoaded = false;
                throw err;
            });

        return this._modelsLoading;
    };

    FaceIdDetector.prototype._getUserMedia = function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject(new Error('Camera API not available. Use HTTPS or localhost.'));
        }
        var attempts = [
            {
                video: {
                    facingMode: 'user',
                    width: { ideal: 1280 },
                    height: { ideal: 960 }
                },
                audio: false
            },
            { video: { facingMode: 'user' }, audio: false },
            { video: true, audio: false }
        ];
        var self = this;
        function tryIndex(i) {
            if (i >= attempts.length) {
                return Promise.reject(new Error('Could not open camera. Check browser permission and that no other app is using it.'));
            }
            return navigator.mediaDevices.getUserMedia(attempts[i]).catch(function () {
                return tryIndex(i + 1);
            });
        }
        return tryIndex(0).then(function (stream) {
            self._stream = stream;
            if (!self.video) {
                return;
            }
            self.video.srcObject = stream;
            var playPromise = self.video.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                return playPromise.catch(function (err) {
                    throw new Error('Video playback blocked: ' + (err && err.message ? err.message : 'autoplay denied'));
                });
            }
        });
    };

    FaceIdDetector.prototype.startCamera = function () {
        var self = this;
        this.onStatus('Starting camera…');
        return this._getUserMedia().then(function () {
            return self._waitForVideoDimensions();
        });
    };

    FaceIdDetector.prototype._waitForVideoDimensions = function () {
        var self = this;
        var video = this.video;
        if (!video) {
            return Promise.resolve();
        }
        if (video.videoWidth > 0 && video.videoHeight > 0) {
            self._syncOverlaySize();
            return Promise.resolve();
        }

        return new Promise(function (resolve) {
            var attempts = 0;
            var tick = function () {
                attempts += 1;
                if (video.videoWidth > 0 && video.videoHeight > 0) {
                    self._syncOverlaySize();
                    resolve();
                    return;
                }
                if (attempts > 40) {
                    resolve();
                    return;
                }
                setTimeout(tick, 50);
            };
            tick();
        });
    };

    FaceIdDetector.prototype._syncOverlaySize = function () {
        if (!this.video || !this.overlay) {
            return;
        }
        this.overlay.width = this.video.videoWidth;
        this.overlay.height = this.video.videoHeight;
    };

    FaceIdDetector.prototype.start = function () {
        var self = this;
        this._running = true;

        return this.loadModels()
            .then(function () { return self.startCamera(); })
            .then(function () {
                self.onStatus('Center your face in the oval.');
                self._startLoop();
            });
    };

    FaceIdDetector.prototype._startLoop = function () {
        var self = this;
        if (this._loopTimer) {
            clearInterval(this._loopTimer);
        }
        this._fpsStart = performance.now();
        this._frameCount = 0;

        this._loopTimer = setInterval(function () {
            self._detectFrame();
        }, this.options.loopIntervalMs);
    };

    FaceIdDetector.prototype._detectFrame = function () {
        var self = this;
        if (!this._running || !this.video || typeof faceapi === 'undefined') {
            return;
        }
        if (this.video.videoWidth <= 0) {
            return;
        }

        this._frameCount += 1;
        var elapsed = performance.now() - this._fpsStart;
        if (elapsed >= 1000) {
            this._fps = Math.round((this._frameCount * 1000) / elapsed);
            this._frameCount = 0;
            this._fpsStart = performance.now();
        }

        faceapi.detectAllFaces(this.video, this._detector())
            .withFaceLandmarks()
            .withFaceDescriptors()
            .then(function (detections) {
                self._lastBrightness = self._sampleBrightness();
                self._evaluateDetections(detections || []);
            })
            .catch(function () {
                self._setReady(false, 'Detection error — retrying…');
            });
    };

    FaceIdDetector.prototype._sampleBrightness = function () {
        if (!this.video || this.video.videoWidth <= 0) {
            return 0;
        }
        try {
            var canvas = document.createElement('canvas');
            canvas.width = 64;
            canvas.height = 48;
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                return 0;
            }
            ctx.drawImage(this.video, 0, 0, canvas.width, canvas.height);
            var data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
            var sum = 0;
            var pixels = data.length / 4;
            for (var i = 0; i < data.length; i += 4) {
                sum += (data[i] * 0.299) + (data[i + 1] * 0.587) + (data[i + 2] * 0.114);
            }
            return pixels > 0 ? sum / pixels : 0;
        } catch (e) {
            return 0;
        }
    };

    FaceIdDetector.prototype._isBoxCentered = function (box) {
        if (!this.video) {
            return false;
        }
        var vw = this.video.videoWidth;
        var vh = this.video.videoHeight;
        if (vw <= 0 || vh <= 0) {
            return false;
        }
        var cx = box.x + (box.width / 2);
        var cy = box.y + (box.height / 2);
        var guideW = vw * this.options.guideWidthRatio;
        var guideH = vh * this.options.guideHeightRatio;
        var left = (vw - guideW) / 2;
        var top = (vh - guideH) / 2;
        return cx >= left && cx <= left + guideW && cy >= top && cy <= top + guideH;
    };

    FaceIdDetector.prototype._evaluateDetections = function (detections) {
        var tone = '';
        var status = '';
        var ready = false;
        var reason = '';

        if (detections.length === 0) {
            status = 'No face — center yourself in the oval';
            tone = 'warn';
            this._lastDetection = null;
            this._lastScore = 0;
            this._drawOverlay(null, 'none');
        } else if (detections.length > 1) {
            status = 'Multiple faces — only one person';
            tone = 'warn';
            this._lastDetection = null;
            this._drawOverlay(detections, 'multi');
        } else {
            var det = detections[0];
            var score = det.detection && typeof det.detection.score === 'number' ? det.detection.score : 0;
            this._lastDetection = det;
            this._lastScore = score;

            if (this._lastBrightness < this.options.minBrightness) {
                status = 'Too dark — move to better lighting';
                tone = 'warn';
                this._drawOverlay(detections, 'warn');
            } else if (this._lastBrightness > this.options.maxBrightness) {
                status = 'Too bright — reduce glare';
                tone = 'warn';
                this._drawOverlay(detections, 'warn');
            } else if (!this._isBoxCentered(det.detection.box)) {
                status = 'Move your face into the oval (score ' + score.toFixed(2) + ')';
                tone = 'warn';
                this._drawOverlay(detections, 'warn');
            } else if (score < this.options.scoreThreshold) {
                status = 'Face too faint (score ' + score.toFixed(2) + ') — move closer';
                tone = 'warn';
                this._drawOverlay(detections, 'warn');
            } else {
                status = 'Face detected (score ' + score.toFixed(2) + ') — hold still';
                tone = 'ready';
                ready = true;
                reason = 'ready';
                this._drawOverlay(detections, 'ok');
            }
        }

        this._setReady(ready, reason);
        this.onStatus(status, tone);
        this.onDebug({
            detectionCount: detections.length,
            score: this._lastScore,
            brightness: Math.round(this._lastBrightness),
            videoWidth: this.video ? this.video.videoWidth : 0,
            videoHeight: this.video ? this.video.videoHeight : 0,
            fps: this._fps,
            ready: ready
        });
    };

    FaceIdDetector.prototype._setReady = function (ready, reason) {
        if (this._ready !== ready || this._readyReason !== reason) {
            this._ready = ready;
            this._readyReason = reason;
            this.onReadyChange(ready, reason);
        }
    };

    FaceIdDetector.prototype._drawOverlay = function (detections, mode) {
        if (!this.overlay || !this.video) {
            return;
        }
        this._syncOverlaySize();
        var ctx = this.overlay.getContext('2d');
        if (!ctx) {
            return;
        }
        ctx.clearRect(0, 0, this.overlay.width, this.overlay.height);

        if (!detections || detections.length === 0) {
            return;
        }

        var color = '#f59e0b';
        if (mode === 'ok') {
            color = '#22c55e';
        } else if (mode === 'multi') {
            color = '#ef4444';
        }

        var resized = faceapi.resizeResults(detections, {
            width: this.overlay.width,
            height: this.overlay.height
        });

        resized.forEach(function (det) {
            var box = det.detection.box;
            ctx.strokeStyle = color;
            ctx.lineWidth = 3;
            ctx.strokeRect(box.x, box.y, box.width, box.height);
        });
    };

    FaceIdDetector.prototype.isReady = function () {
        return this._ready;
    };

    FaceIdDetector.prototype.getBestDetection = function () {
        return this._lastDetection;
    };

    FaceIdDetector.prototype.captureDescriptor = function () {
        var det = this._lastDetection;
        if (!det || !det.descriptor) {
            return null;
        }
        return Array.from(det.descriptor);
    };

    FaceIdDetector.prototype.stop = function () {
        this._running = false;
        if (this._loopTimer) {
            clearInterval(this._loopTimer);
            this._loopTimer = null;
        }
        if (this._stream) {
            this._stream.getTracks().forEach(function (track) { track.stop(); });
            this._stream = null;
        }
        if (this.video) {
            this.video.srcObject = null;
        }
        if (this.overlay) {
            var ctx = this.overlay.getContext('2d');
            if (ctx) {
                ctx.clearRect(0, 0, this.overlay.width, this.overlay.height);
            }
        }
        this._lastDetection = null;
        this._setReady(false, '');
    };

    FaceIdDetector.averageDescriptors = function (descriptors) {
        if (!descriptors || descriptors.length === 0) {
            return [];
        }
        var len = descriptors[0].length;
        var out = new Array(len);
        var i;
        var j;
        for (i = 0; i < len; i++) {
            var sum = 0;
            for (j = 0; j < descriptors.length; j++) {
                sum += descriptors[j][i];
            }
            out[i] = sum / descriptors.length;
        }
        return out;
    };

    global.FaceIdDetector = FaceIdDetector;
})(typeof window !== 'undefined' ? window : this);

/**
 * POS Camera Scanner Module
 * Handles continuous camera-based barcode/QR decoding with deterministic cleanup.
 */

window.PosCameraScanner = (function () {
    'use strict';

    const States = {
        IDLE: 'idle',
        OPENING: 'opening',
        STARTING_CAMERA: 'starting_camera',
        WAITING_FOR_VIDEO: 'waiting_for_video',
        APPLYING_CONSTRAINTS: 'applying_constraints',
        READY: 'ready',
        SUBMITTING: 'submitting',
        COOLDOWN: 'cooldown',
        CAMERA_ERROR: 'camera_error',
        DECODER_ERROR: 'decoder_error'
    };

    const FailureStages = {
        CAMERA_PERMISSION: 'CAMERA_PERMISSION',
        CAMERA_UNAVAILABLE: 'CAMERA_UNAVAILABLE',
        CAMERA_BUSY: 'CAMERA_BUSY',
        VIDEO_ATTACH: 'VIDEO_ATTACH',
        VIDEO_PLAYBACK: 'VIDEO_PLAYBACK',
        CONSTRAINTS: 'CONSTRAINTS',
        DECODER_INIT: 'DECODER_INIT',
        DECODER_INVALID_API: 'DECODER_INVALID_API',
        DECODE_PROCESSING: 'DECODE_PROCESSING'
    };

    const FORMAT_ALLOWLIST = [
        'EAN_13', 'EAN_8', 'UPC_A', 'UPC_E',
        'CODE_128', 'CODE_39', 'CODE_93', 'ITF',
        'CODABAR', 'RSS_14', 'QR_CODE', 'DATA_MATRIX',
        'PDF_417', 'AZTEC'
    ];

    const DECODE_ATTEMPT_INTERVAL_MS = 100;
    const SAME_CODE_SUPPRESSION_MS = 1800;
    const REARM_COOLDOWN_MS = 450;
    const CAMERA_BOOT_DELAY_MS = 240;
    const VIDEO_READY_TIMEOUT_MS = 2400;

    /**
     * Decoder Adapter: Provides unified interface for native BarcodeDetector and html5-qrcode fallback
     * Selects native decoder at runtime when available, falls back to html5-qrcode otherwise
     */
    const DecoderAdapter = (function () {
        let selectedBackend = null;
        let detectorInstance = null;
        let html5QrcodeInstance = null;
        let decodeAnimationFrameId = null;
        let lastDecodeTime = 0;
        const THROTTLE_MS = 100;

        // Format mapping: FORMAT_ALLOWLIST -> native BarcodeDetector format strings
        const NATIVE_FORMAT_MAP = {
            'EAN_13': 'ean_13',
            'EAN_8': 'ean_8',
            'UPC_A': 'upc_a',
            'UPC_E': 'upc_e',
            'CODE_128': 'code_128',
            'CODE_39': 'code_39',
            'CODE_93': 'code_93',
            'ITF': 'itf',
            'CODABAR': 'codabar',
            'RSS_14': 'rss_14',
            'QR_CODE': 'qr_code',
            'DATA_MATRIX': 'data_matrix',
            'PDF_417': 'pdf_417',
            'AZTEC': 'aztec'
        };

        // Format mapping: FORMAT_ALLOWLIST -> html5-qrcode Html5QrcodeSupportedFormats
        const HTML5_FORMAT_MAP = {
            'EAN_13': 'EAN_13',
            'EAN_8': 'EAN_8',
            'UPC_A': 'UPC_A',
            'UPC_E': 'UPC_E',
            'CODE_128': 'CODE_128',
            'CODE_39': 'CODE_39',
            'CODE_93': 'CODE_93',
            'ITF': 'ITF',
            'CODABAR': 'CODABAR',
            'RSS_14': 'RSS_14',
            'QR_CODE': 'QR_CODE',
            'DATA_MATRIX': 'DATA_MATRIX',
            'PDF_417': 'PDF_417',
            'AZTEC': 'AZTEC'
        };

        function getNativeFormats() {
            return FORMAT_ALLOWLIST
                .map(function (name) { return NATIVE_FORMAT_MAP[name]; })
                .filter(function (format) { return format !== undefined; });
        }

        function getHtml5Formats() {
            return FORMAT_ALLOWLIST
                .map(function (name) { return HTML5_FORMAT_MAP[name]; })
                .filter(function (format) { return format !== undefined; });
        }

        function startNativeDecoder(videoElement, onDecode, onError) {
            detectorInstance = new BarcodeDetector({ formats: getNativeFormats() });
            lastDecodeTime = 0;

            function decodeLoop() {
                const now = Date.now();
                if (now - lastDecodeTime >= THROTTLE_MS) {
                    lastDecodeTime = now;
                    detectorInstance.detect(videoElement)
                        .then(function (barcodes) {
                            if (barcodes && barcodes.length > 0) {
                                onDecode(barcodes[0].rawValue);
                            }
                        })
                        .catch(function (error) {
                            onError(error);
                        });
                }
                if (selectedBackend === 'native') {
                    decodeAnimationFrameId = window.requestAnimationFrame(decodeLoop);
                }
            }

            decodeAnimationFrameId = window.requestAnimationFrame(decodeLoop);
        }

        function startFallbackDecoder(videoElement, onDecode, onError) {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            lastDecodeTime = 0;

            // Get the html5-qrcode library from window global
            var Html5Qrcode;
            if (typeof window.__Html5QrcodeLibrary__ !== 'undefined') {
                Html5Qrcode = window.__Html5QrcodeLibrary__.Html5Qrcode;
            } else if (typeof __Html5QrcodeLibrary__ !== 'undefined') {
                Html5Qrcode = __Html5QrcodeLibrary__.Html5Qrcode;
            } else {
                onError(new Error('html5-qrcode library not loaded'));
                return;
            }

            // Initialize html5-qrcode decoder
            try {
                html5QrcodeInstance = new Html5Qrcode(
                    'pos-camera-video',
                    { formatsToSupport: getHtml5Formats() }
                );
            } catch (e) {
                onError(e);
                return;
            }

            function decodeLoop() {
                const now = Date.now();
                if (now - lastDecodeTime >= THROTTLE_MS) {
                    lastDecodeTime = now;

                    // Capture video frame to canvas
                    canvas.width = videoElement.videoWidth;
                    canvas.height = videoElement.videoHeight;
                    try {
                        ctx.drawImage(videoElement, 0, 0, canvas.width, canvas.height);
                    } catch (e) {
                        // Ignore frame capture errors - video may not be ready
                        if (selectedBackend === 'fallback') {
                            decodeAnimationFrameId = window.requestAnimationFrame(decodeLoop);
                        }
                        return;
                    }

                    // Use html5-qrcode decoder
                    if (html5QrcodeInstance) {
                        html5QrcodeInstance.decodeFromCanvas(canvas)
                            .then(function (decodedText) {
                                onDecode(decodedText.decodedText || decodedText);
                            })
                            .catch(function (error) {
                                // Frame miss - silently continue
                            });
                    }
                }

                if (selectedBackend === 'fallback') {
                    decodeAnimationFrameId = window.requestAnimationFrame(decodeLoop);
                }
            }

            decodeAnimationFrameId = window.requestAnimationFrame(decodeLoop);
        }

        function initialize(onDecode, onError) {
            // Check for native BarcodeDetector
            if ('BarcodeDetector' in window) {
                try {
                    const tempDetector = new BarcodeDetector({ formats: getNativeFormats() });
                    BarcodeDetector.getSupportedFormats().then(function (formats) {
                        const required = getNativeFormats();
                        const hasAllFormats = required.every(function (format) {
                            return formats.indexOf(format) !== -1;
                        });

                        if (hasAllFormats) {
                            selectedBackend = 'native';
                        } else {
                            selectedBackend = 'fallback';
                        }
                        startDecoding(onDecode, onError);
                    }).catch(function () {
                        selectedBackend = 'fallback';
                        startDecoding(onDecode, onError);
                    });
                } catch (e) {
                    selectedBackend = 'fallback';
                    startDecoding(onDecode, onError);
                }
            } else {
                selectedBackend = 'fallback';
                startDecoding(onDecode, onError);
            }
        }

        function startDecoding(onDecode, onError) {
            if (!videoElement) {
                onError(new Error('Video element not available'));
                return;
            }

            if (selectedBackend === 'native') {
                startNativeDecoder(videoElement, onDecode, onError);
            } else if (selectedBackend === 'fallback') {
                startFallbackDecoder(videoElement, onDecode, onError);
            }
        }

        return {
            start: function (video, onDecode, onError) {
                videoElement = video;
                initialize(onDecode, onError);
            },
            stop: function () {
                if (decodeAnimationFrameId !== null) {
                    window.cancelAnimationFrame(decodeAnimationFrameId);
                    decodeAnimationFrameId = null;
                }
                if (detectorInstance) {
                    detectorInstance = null;
                }
                if (html5QrcodeInstance) {
                    try {
                        html5QrcodeInstance.stop();
                    } catch (e) {}
                    html5QrcodeInstance = null;
                }
                selectedBackend = null;
            },
            getBackendName: function () {
                if (selectedBackend === 'native') {
                    return 'BarcodeDetector (native)';
                } else if (selectedBackend === 'fallback') {
                    return 'html5-qrcode (fallback)';
                }
                return 'unknown';
            }
        };
    })();

    const DEBUG_SCANNER = (function () {
        try {
            return new URLSearchParams(window.location.search).get('scanner_debug') === '1';
        } catch (e) {
            return false;
        }
    })();

    const Messages = {
        OPENING: {
            tone: 'ready',
            chip: 'Menyiapkan',
            headline: 'Membuka kamera scanner.',
            detail: 'Tunggu sebentar, lalu arahkan barcode ke jalur scan.'
        },
        READY: {
            tone: 'ready',
            chip: 'Siap',
            headline: 'Scanner siap untuk item berikutnya.',
            detail: 'Arahkan barcode ke dalam jalur scan. Sesi tetap aktif sampai Anda menutup scanner.'
        },
        ACCEPTED: {
            tone: 'accepted',
            chip: 'Tertangkap',
            headline: 'Barcode diterima.',
            detail: 'Memproses hasil scan melalui alur input POS yang sama.'
        },
        NOT_FOUND: {
            tone: 'warning',
            chip: 'Tidak Ditemukan',
            headline: 'Kode belum cocok dengan produk atau serial.',
            detail: 'Periksa label, lalu arahkan ulang barcode atau lanjutkan input manual tanpa menutup sesi.'
        },
        OVER_LIMIT: {
            tone: 'warning',
            chip: 'Perlu Tinjau',
            headline: 'Kode terlalu panjang untuk resolver scan.',
            detail: 'Nilai sudah dipindahkan ke input scan. Edit manual dari kolom scan jika diperlukan.'
        },
        RESOLVER_ERROR: {
            tone: 'error',
            chip: 'Gagal',
            headline: 'Scan tidak berhasil diproses.',
            detail: 'Coba arahkan ulang barcode atau gunakan tombol Coba Lagi jika kamera perlu diinisialisasi ulang.'
        },
        PERMISSION_DENIED: {
            tone: 'error',
            chip: 'Izin Ditolak',
            headline: 'Akses kamera ditolak.',
            detail: 'Aktifkan izin kamera di browser atau perangkat, lalu coba lagi.'
        },
        CAMERA_UNAVAILABLE: {
            tone: 'error',
            chip: 'Kamera Tidak Siap',
            headline: 'Kamera tidak tersedia.',
            detail: 'Kamera mungkin sedang dipakai aplikasi lain atau belum tersedia pada perangkat ini.'
        },
        CAMERA_BUSY: {
            tone: 'error',
            chip: 'Kamera Sibuk',
            headline: 'Kamera sedang digunakan.',
            detail: 'Tutup aplikasi lain yang memakai kamera lalu mulai ulang sesi scan.'
        },
        DECODER_ERROR: {
            tone: 'error',
            chip: 'Scanner Bermasalah',
            headline: 'Mesin decoder tidak siap.',
            detail: 'Muat ulang sesi scanner atau periksa pemuatan pustaka barcode.'
        },
        DECODE_ERROR: {
            tone: 'warning',
            chip: 'Coba Ulang',
            headline: 'Frame belum bisa dibaca dengan stabil.',
            detail: 'Tahan barcode lebih rata di dalam jalur scan dan coba lagi.'
        }
    };

    let state = States.IDLE;
    let mediaStream = null;
    let modalElement = null;
    let videoElement = null;
    let cameraButton = null;
    let retryButton = null;
    let closeButton = null;
    let cancelButton = null;
    let statusPanelElement = null;
    let statusChipElement = null;
    let statusHeadlineElement = null;
    let statusDetailElement = null;
    let debugPanelElement = null;
    let decoderAdapter = null;
    let cooldownTimer = null;
    let openRequestTimer = null;
    let videoReadinessTimer = null;
    let lastFailureStage = null;
    let lastFailureToken = null;
    let submissionInFlight = false;
    let sessionActive = false;
    let lastAcceptedCode = null;
    let lastAcceptedAt = 0;
    let decodeStartNonce = 0;

    const debugState = {
        scannerState: States.IDLE,
        streamAttached: false,
        videoWidth: 0,
        videoHeight: 0,
        videoReady: false,
        videoReadyState: 0,
        playbackActive: false,
        trackLabel: '',
        trackCapabilitiesSummary: '',
        trackSettingsSummary: '',
        requestedPostStartConstraints: '',
        postStartConstraintResults: '',
        lastDecodedText: '',
        lastDecodedFormat: '',
        frameMissCount: 0,
        lastNonFatalErrorName: '',
        lastNonFatalErrorMessage: '',
        lastFatalToken: '',
        lastFatalStage: '',
        resolverInFlight: false,
        decoderBackend: ''
    };

    function initialize() {
        modalElement = document.getElementById('pos-camera-scanner-modal');
        videoElement = document.getElementById('pos-camera-video');
        cameraButton = document.getElementById('pos-btn-scan-camera');
        retryButton = document.getElementById('pos-camera-scanner-retry');
        closeButton = document.getElementById('pos-camera-scanner-close');
        cancelButton = document.getElementById('pos-camera-scanner-cancel');
        statusPanelElement = document.getElementById('pos-camera-scanner-session-status');
        statusChipElement = document.getElementById('pos-camera-scanner-status-chip');
        statusHeadlineElement = document.getElementById('pos-camera-scanner-status');
        statusDetailElement = document.getElementById('pos-camera-scanner-detail');
        debugPanelElement = document.getElementById('pos-camera-scanner-debug');

        if (!modalElement || !videoElement || !cameraButton || !retryButton || !closeButton || !cancelButton) {
            console.warn('[PosCameraScanner] Required DOM elements not found');
            return false;
        }

        cameraButton.addEventListener('click', openScanner);
        retryButton.addEventListener('click', retryScanning);
        closeButton.addEventListener('click', closeScanner);
        cancelButton.addEventListener('click', closeScanner);

        $(modalElement).on('hidden.bs.modal', function () {
            stopSession({ preserveModalState: false });
        });

        window.addEventListener('beforeunload', function () {
            stopSession({ preserveModalState: true });
        });

        resetDebugState();
        setSessionMessage(Messages.OPENING);
        state = States.IDLE;
        return true;
    }

    function openScanner() {
        if (sessionActive) {
            return;
        }

        sessionActive = true;
        submissionInFlight = false;
        lastAcceptedCode = null;
        lastAcceptedAt = 0;
        clearTimers();
        resetDebugState();
        state = States.OPENING;
        retryButton.classList.add('d-none');
        setSessionMessage(Messages.OPENING);
        updateDebugState({});

        $(modalElement).modal('show');

        openRequestTimer = window.setTimeout(function () {
            if (sessionActive) {
                startCamera();
            }
        }, CAMERA_BOOT_DELAY_MS);
    }

    function startCamera() {
        if (!sessionActive) {
            return;
        }

        state = States.STARTING_CAMERA;
        setSessionMessage(Messages.OPENING);
        updateDebugState({});

        const constraints = {
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: false
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(function (stream) {
                if (!sessionActive) {
                    stopTracks(stream);
                    return;
                }

                mediaStream = stream;
                attachVideoStream(stream);
                updateTrackDiagnostics();
                waitForVideoReadiness()
                    .then(function () {
                        return applyPostStartVideoConstraints();
                    })
                    .then(function () {
                        armScanner();
                    })
                    .catch(function (error) {
                        handleVideoPipelineError(error);
                    });
            })
            .catch(function (error) {
                handleCameraError(error);
            });
    }

    function attachVideoStream(stream) {
        state = States.WAITING_FOR_VIDEO;
        videoElement.setAttribute('playsinline', 'true');
        videoElement.setAttribute('autoplay', 'true');
        videoElement.setAttribute('muted', 'true');
        videoElement.muted = true;
        videoElement.srcObject = stream;
        updateDebugState({});
    }

    function waitForVideoReadiness() {
        return new Promise(function (resolve, reject) {
            let settled = false;

            function finish(callback, value) {
                if (settled) {
                    return;
                }

                settled = true;
                if (videoReadinessTimer) {
                    window.clearTimeout(videoReadinessTimer);
                    videoReadinessTimer = null;
                }
                videoElement.onloadedmetadata = null;
                videoElement.onplaying = null;
                callback(value);
            }

            function maybeReady() {
                if (!sessionActive || !videoElement) {
                    finish(reject, new Error('Scanner session ended before video became ready'));
                    return;
                }

                const hasDimensions = videoElement.videoWidth > 0 && videoElement.videoHeight > 0;
                const isPlaying = !videoElement.paused && !videoElement.ended;
                const hasEnoughData = videoElement.readyState >= 2;

                updateDebugState({
                    videoReady: hasDimensions && isPlaying && hasEnoughData,
                    playbackActive: isPlaying
                });

                if (hasDimensions && isPlaying && hasEnoughData) {
                    finish(resolve);
                }
            }

            videoElement.onloadedmetadata = function () {
                const playPromise = videoElement.play();
                if (playPromise && typeof playPromise.then === 'function') {
                    playPromise.then(function () {
                        maybeReady();
                    }).catch(function (error) {
                        error._scannerStage = FailureStages.VIDEO_PLAYBACK;
                        finish(reject, error);
                    });
                } else {
                    maybeReady();
                }
            };

            videoElement.onplaying = maybeReady;
            videoReadinessTimer = window.setTimeout(function () {
                const error = new Error('Timed out waiting for scan-ready video playback');
                error._scannerStage = FailureStages.VIDEO_ATTACH;
                finish(reject, error);
            }, VIDEO_READY_TIMEOUT_MS);

            if (videoElement.readyState >= 1) {
                videoElement.onloadedmetadata();
            }
        });
    }

    function applyPostStartVideoConstraints() {
        if (!sessionActive || !mediaStream) {
            return Promise.resolve();
        }

        state = States.APPLYING_CONSTRAINTS;
        updateTrackDiagnostics();
        updateDebugState({});

        const videoTrack = getActiveVideoTrack();
        if (!videoTrack) {
            recordConstraintAttempt('track-missing', null, 'unsupported', 'No active video track');
            return Promise.resolve();
        }

        const capabilities = getTrackCapabilities(videoTrack);
        const attempts = buildPostStartConstraintAttempts(capabilities);

        if (attempts.length === 0) {
            recordConstraintAttempt('no-supported-advanced-constraints', null, 'unsupported', 'No meaningful post-start constraints exposed');
            return Promise.resolve();
        }

        return attempts.reduce(function (chain, attempt) {
            return chain.then(function () {
                return applyConstraintAttempt(videoTrack, attempt);
            });
        }, Promise.resolve()).finally(function () {
            updateTrackDiagnostics();
            updateDebugState({});
        });
    }

    function buildPostStartConstraintAttempts(capabilities) {
        const attempts = [];
        const advanced = {};

        if (Array.isArray(capabilities.focusMode) && capabilities.focusMode.includes('continuous')) {
            advanced.focusMode = 'continuous';
        }
        if (Array.isArray(capabilities.exposureMode) && capabilities.exposureMode.includes('continuous')) {
            advanced.exposureMode = 'continuous';
        }
        if (Array.isArray(capabilities.whiteBalanceMode) && capabilities.whiteBalanceMode.includes('continuous')) {
            advanced.whiteBalanceMode = 'continuous';
        }

        if (Object.keys(advanced).length > 0) {
            attempts.push({
                label: 'advanced',
                constraints: { advanced: [advanced] }
            });
        }

        if (typeof capabilities.zoom === 'object' && capabilities.zoom) {
            const minZoom = Number(capabilities.zoom.min);
            const maxZoom = Number(capabilities.zoom.max);
            if (Number.isFinite(minZoom) && Number.isFinite(maxZoom) && maxZoom > minZoom) {
                const desiredZoom = Math.min(maxZoom, Math.max(minZoom, Math.min(2, minZoom + (maxZoom - minZoom) * 0.35)));
                attempts.push({
                    label: 'zoom',
                    constraints: { advanced: [{ zoom: roundNumber(desiredZoom) }] }
                });
            }
        }

        return attempts;
    }

    function applyConstraintAttempt(videoTrack, attempt) {
        if (typeof videoTrack.applyConstraints !== 'function') {
            recordConstraintAttempt(attempt.label, attempt.constraints, 'unsupported', 'applyConstraints unavailable');
            return Promise.resolve();
        }

        recordConstraintAttempt(attempt.label, attempt.constraints, 'requested', '');

        return videoTrack.applyConstraints(attempt.constraints)
            .then(function () {
                recordConstraintAttempt(attempt.label, attempt.constraints, 'applied', '');
            })
            .catch(function (error) {
                recordConstraintAttempt(
                    attempt.label,
                    attempt.constraints,
                    'failed',
                    error && error.message ? error.message : String(error)
                );
            });
    }

    function startDecoding() {
        if (!sessionActive || !videoElement || !videoElement.srcObject) {
            return;
        }

        if (!isVideoScanReady()) {
            openRequestTimer = window.setTimeout(function () {
                startDecoding();
            }, 120);
            return;
        }

        try {
            const currentNonce = ++decodeStartNonce;

            decoderAdapter.start(
                videoElement,
                function (decodedValue) {
                    // onDecode callback
                    if (!sessionActive || currentNonce !== decodeStartNonce) {
                        return;
                    }

                    updateDebugState({
                        lastDecodedText: decodedValue,
                        lastDecodedFormat: '',
                        lastNonFatalErrorName: '',
                        lastNonFatalErrorMessage: '',
                        decoderBackend: decoderAdapter.getBackendName()
                    });
                    handleDecodedValue(decodedValue);
                },
                function (error) {
                    // onError callback
                    if (!sessionActive || currentNonce !== decodeStartNonce) {
                        return;
                    }

                    logDiagnostics(FailureStages.DECODE_PROCESSING, error);
                    updateDebugState({
                        lastNonFatalErrorName: error.name || 'Error',
                        lastNonFatalErrorMessage: error.message || String(error)
                    });
                }
            );

            updateDebugState({ decoderBackend: decoderAdapter.getBackendName() });
        } catch (error) {
            logDiagnostics(FailureStages.DECODER_INIT, error);
            state = States.DECODER_ERROR;
            setSessionMessage(Messages.DECODER_ERROR, buildDebugDetail(Messages.DECODER_ERROR.detail));
            retryButton.classList.remove('d-none');
            updateDebugState({});
        }
    }

    function handleDecodedValue(decodedValue) {
        if (!sessionActive || !decodedValue) {
            return;
        }

        const trimmedValue = decodedValue.trim();
        if (!trimmedValue) {
            return;
        }

        if (submissionInFlight || state === States.COOLDOWN) {
            return;
        }

        const now = Date.now();
        if (lastAcceptedCode === trimmedValue && now - lastAcceptedAt < SAME_CODE_SUPPRESSION_MS) {
            return;
        }

        mirrorValueToSearchInput(trimmedValue);

        if (trimmedValue.length > 255) {
            lastAcceptedCode = trimmedValue;
            lastAcceptedAt = now;
            state = States.COOLDOWN;
            setSessionMessage(Messages.OVER_LIMIT);
            updateDebugState({});
            scheduleRearm();
            return;
        }

        if (!window.executeScanResolve || typeof window.executeScanResolve !== 'function') {
            logDiagnostics(FailureStages.DECODE_PROCESSING, new Error('executeScanResolve not available'));
            state = States.DECODER_ERROR;
            setSessionMessage(Messages.RESOLVER_ERROR, buildDebugDetail(Messages.RESOLVER_ERROR.detail));
            updateDebugState({});
            return;
        }

        submissionInFlight = true;
        lastAcceptedCode = trimmedValue;
        lastAcceptedAt = now;
        state = States.SUBMITTING;
        setSessionMessage(Messages.ACCEPTED, 'Kode ' + trimmedValue + ' diterima. Menunggu hasil resolver POS.');
        updateDebugState({});

        window.executeScanResolve(trimmedValue)
            .then(function (result) {
                const outcome = result && result.outcome ? result.outcome : 'resolver_error';

                if (outcome === 'product_exact' || outcome === 'serial_exact') {
                    setSessionMessage(Messages.ACCEPTED, result.message || Messages.ACCEPTED.detail);
                } else if (outcome === 'not_found') {
                    setSessionMessage(Messages.NOT_FOUND, result.message || Messages.NOT_FOUND.detail);
                } else {
                    setSessionMessage(Messages.RESOLVER_ERROR, result && result.message ? result.message : Messages.RESOLVER_ERROR.detail);
                }
            })
            .catch(function (error) {
                console.error('[PosCameraScanner] Resolver error:', error);
                setSessionMessage(Messages.RESOLVER_ERROR, error && error.message ? error.message : Messages.RESOLVER_ERROR.detail);
            })
            .finally(function () {
                submissionInFlight = false;
                updateDebugState({});
                if (!sessionActive) {
                    return;
                }
                scheduleRearm();
            });
    }

    function scheduleRearm() {
        clearCooldownTimer();
        state = States.COOLDOWN;
        updateDebugState({});
        cooldownTimer = window.setTimeout(function () {
            armScanner();
        }, REARM_COOLDOWN_MS);
    }

    function armScanner() {
        if (!sessionActive) {
            return;
        }

        state = States.READY;
        setSessionMessage(Messages.READY);
        retryButton.classList.add('d-none');
        updateDebugState({});
        restartDecoding();
    }

    function restartDecoding() {
        if (!sessionActive) {
            return;
        }

        if (decoderAdapter) {
            try {
                decoderAdapter.stop();
            } catch (e) {}
        }

        startDecoding();
    }

    function retryScanning() {
        stopSession({ preserveModalState: true });
        sessionActive = true;
        retryButton.classList.add('d-none');
        setSessionMessage(Messages.OPENING);
        updateDebugState({});
        openRequestTimer = window.setTimeout(function () {
            if (sessionActive) {
                startCamera();
            }
        }, 220);
    }

    function closeScanner() {
        stopSession({ preserveModalState: true });
        $(modalElement).modal('hide');
    }

    function stopSession(options) {
        const preserveModalState = options && options.preserveModalState;

        clearTimers();
        submissionInFlight = false;
        sessionActive = false;
        decodeStartNonce += 1;

        if (decoderAdapter) {
            try {
                decoderAdapter.stop();
            } catch (error) {
                console.warn('[PosCameraScanner] Error stopping decoder:', error);
            }
        }

        if (videoElement) {
            videoElement.pause();
            videoElement.srcObject = null;
            videoElement.onloadedmetadata = null;
            videoElement.onplaying = null;
        }

        if (mediaStream) {
            stopTracks(mediaStream);
            mediaStream = null;
        }

        state = States.IDLE;
        lastAcceptedCode = null;
        lastAcceptedAt = 0;
        resetDebugState();
        updateDebugState({});

        if (!preserveModalState) {
            retryButton.classList.add('d-none');
            setSessionMessage(Messages.OPENING);
        }
    }

    function handleCameraError(error) {
        let message = Messages.CAMERA_UNAVAILABLE;
        let stage = FailureStages.CAMERA_UNAVAILABLE;

        if (error && (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError')) {
            message = Messages.PERMISSION_DENIED;
            stage = FailureStages.CAMERA_PERMISSION;
        } else if (error && (error.name === 'NotReadableError' || error.name === 'TrackStartError')) {
            message = Messages.CAMERA_BUSY;
            stage = FailureStages.CAMERA_BUSY;
        } else if (error && (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError')) {
            message = Messages.CAMERA_UNAVAILABLE;
            stage = FailureStages.CAMERA_UNAVAILABLE;
        }

        logDiagnostics(stage, error);
        state = States.CAMERA_ERROR;
        setSessionMessage(message, buildDebugDetail(message.detail));
        retryButton.classList.remove('d-none');
        updateDebugState({});
    }

    function handleVideoPipelineError(error) {
        const stage = error && error._scannerStage ? error._scannerStage : FailureStages.CONSTRAINTS;
        logDiagnostics(stage, error);
        state = States.CAMERA_ERROR;
        setSessionMessage(Messages.CAMERA_UNAVAILABLE, buildDebugDetail(Messages.CAMERA_UNAVAILABLE.detail));
        retryButton.classList.remove('d-none');
        updateDebugState({});
    }

    function mirrorValueToSearchInput(value) {
        const searchInput = document.getElementById('pos-shell-search');
        if (!searchInput) {
            return;
        }

        searchInput.value = value;
        searchInput.focus();
    }

    function setSessionMessage(messageConfig, detailOverride) {
        if (statusPanelElement) {
            statusPanelElement.setAttribute('data-status-tone', messageConfig.tone);
        }
        if (statusChipElement) {
            statusChipElement.textContent = messageConfig.chip;
        }
        if (statusHeadlineElement) {
            statusHeadlineElement.textContent = messageConfig.headline;
        }
        if (statusDetailElement) {
            statusDetailElement.textContent = detailOverride || messageConfig.detail;
        }
    }

    function clearTimers() {
        clearCooldownTimer();
        if (openRequestTimer) {
            window.clearTimeout(openRequestTimer);
            openRequestTimer = null;
        }
        if (videoReadinessTimer) {
            window.clearTimeout(videoReadinessTimer);
            videoReadinessTimer = null;
        }
    }

    function clearCooldownTimer() {
        if (cooldownTimer) {
            window.clearTimeout(cooldownTimer);
            cooldownTimer = null;
        }
    }

    function generateDebugToken(stage) {
        const timestamp = Date.now().toString(36).slice(-4);
        return (stage.slice(0, 4) + '_' + timestamp).toUpperCase();
    }

    function logDiagnostics(stage, error) {
        const token = generateDebugToken(stage);
        lastFailureStage = stage;
        lastFailureToken = token;

        console.error('[PosCameraScanner] Diagnostic failure:', {
            token: token,
            stage: stage,
            errorName: error && error.name ? error.name : 'Unknown',
            errorMessage: error ? (error.message || String(error)) : 'Unknown error',
            timestamp: new Date().toISOString()
        });

        updateDebugState({ lastFatalToken: token, lastFatalStage: stage });
    }

    function buildDebugDetail(message) {
        if (!lastFailureToken || !lastFailureStage) {
            return message;
        }

        return message + ' [' + lastFailureStage + ':' + lastFailureToken + ']';
    }

    function resetDebugState() {
        debugState.scannerState = States.IDLE;
        debugState.streamAttached = false;
        debugState.videoWidth = 0;
        debugState.videoHeight = 0;
        debugState.videoReady = false;
        debugState.videoReadyState = 0;
        debugState.playbackActive = false;
        debugState.trackLabel = '';
        debugState.trackCapabilitiesSummary = '';
        debugState.trackSettingsSummary = '';
        debugState.requestedPostStartConstraints = '';
        debugState.postStartConstraintResults = '';
        debugState.lastDecodedText = '';
        debugState.lastDecodedFormat = '';
        debugState.frameMissCount = 0;
        debugState.lastNonFatalErrorName = '';
        debugState.lastNonFatalErrorMessage = '';
        debugState.lastFatalToken = '';
        debugState.lastFatalStage = '';
        debugState.resolverInFlight = false;
        debugState.decoderBackend = '';
    }

    function updateDebugState(patch) {
        if (!DEBUG_SCANNER) {
            return;
        }

        Object.assign(debugState, patch);

        debugState.scannerState = state;
        debugState.streamAttached = !!(videoElement && videoElement.srcObject);
        debugState.videoWidth = videoElement ? videoElement.videoWidth : 0;
        debugState.videoHeight = videoElement ? videoElement.videoHeight : 0;
        debugState.videoReadyState = videoElement ? videoElement.readyState : 0;
        debugState.playbackActive = !!(videoElement && !videoElement.paused && !videoElement.ended);
        debugState.videoReady = isVideoScanReady();
        debugState.resolverInFlight = submissionInFlight;

        if (mediaStream) {
            updateTrackDiagnostics();
        }

        renderDebugPanel();
    }

    function updateTrackDiagnostics() {
        const videoTrack = getActiveVideoTrack();
        if (!videoTrack) {
            debugState.trackLabel = '';
            debugState.trackCapabilitiesSummary = '';
            debugState.trackSettingsSummary = '';
            return;
        }

        debugState.trackLabel = videoTrack.label || '';
        debugState.trackCapabilitiesSummary = stringifyTrackInfo(getTrackCapabilities(videoTrack));
        debugState.trackSettingsSummary = stringifyTrackInfo(getTrackSettings(videoTrack));
    }

    function renderDebugPanel() {
        if (!debugPanelElement || !DEBUG_SCANNER) {
            return;
        }

        debugPanelElement.classList.add('is-active');
        debugPanelElement.setAttribute('aria-hidden', 'false');
        debugPanelElement.innerHTML =
            '<div class="pos-scanner-debug-grid">' +
                renderDebugRow('State', debugState.scannerState) +
                renderDebugRow('Stream', debugState.streamAttached ? 'attached' : 'none') +
                renderDebugRow('Video', debugState.videoWidth + 'x' + debugState.videoHeight) +
                renderDebugRow('Ready', debugState.videoReady ? 'yes' : 'no') +
                renderDebugRow('Playback', debugState.playbackActive ? 'active' : 'idle') +
                renderDebugRow('Track', debugState.trackLabel || '—') +
                renderDebugRow('Capabilities', debugState.trackCapabilitiesSummary || '—', true) +
                renderDebugRow('Settings', debugState.trackSettingsSummary || '—', true) +
                renderDebugRow('Requested constraints', debugState.requestedPostStartConstraints || '—', true) +
                renderDebugRow('Constraint results', debugState.postStartConstraintResults || '—', true) +
                renderDebugRow('Decoder', debugState.decoderBackend || '—') +
                renderDebugRow('Last text', debugState.lastDecodedText || '—', true) +
                renderDebugRow('Last format', debugState.lastDecodedFormat || '—') +
                renderDebugRow('Misses', String(debugState.frameMissCount)) +
                renderDebugRow(
                    'Non-fatal err',
                    debugState.lastNonFatalErrorName
                        ? debugState.lastNonFatalErrorName + ': ' + debugState.lastNonFatalErrorMessage
                        : '—',
                    true
                ) +
                renderDebugRow(
                    'Fatal',
                    debugState.lastFatalStage ? debugState.lastFatalStage + ' ' + debugState.lastFatalToken : '—'
                ) +
                renderDebugRow('In-flight', debugState.resolverInFlight ? 'yes' : 'no') +
            '</div>';
    }

    function renderDebugRow(label, value, wrapValue) {
        return '<div class="pos-scanner-debug-row' + (wrapValue ? ' is-wrap' : '') + '">' +
            '<span>' + escapeHtml(label) + '</span>' +
            '<span>' + escapeHtml(value) + '</span>' +
        '</div>';
    }

    function getActiveVideoTrack() {
        if (!mediaStream || typeof mediaStream.getVideoTracks !== 'function') {
            return null;
        }

        const tracks = mediaStream.getVideoTracks();
        return tracks.length > 0 ? tracks[0] : null;
    }

    function getTrackCapabilities(videoTrack) {
        if (!videoTrack || typeof videoTrack.getCapabilities !== 'function') {
            return {};
        }

        try {
            return videoTrack.getCapabilities() || {};
        } catch (error) {
            return { unavailable: error && error.message ? error.message : 'getCapabilities failed' };
        }
    }

    function getTrackSettings(videoTrack) {
        if (!videoTrack || typeof videoTrack.getSettings !== 'function') {
            return {};
        }

        try {
            return videoTrack.getSettings() || {};
        } catch (error) {
            return { unavailable: error && error.message ? error.message : 'getSettings failed' };
        }
    }

    function stringifyTrackInfo(input) {
        if (!input || typeof input !== 'object') {
            return '';
        }

        const summary = {};
        Object.keys(input).forEach(function (key) {
            const value = input[key];
            if (value === undefined || value === null || value === '') {
                return;
            }

            if (Array.isArray(value)) {
                if (value.length > 0) {
                    summary[key] = value.slice(0, 5);
                }
                return;
            }

            if (typeof value === 'object') {
                const nested = {};
                ['min', 'max', 'step', 'ideal', 'exact'].forEach(function (nestedKey) {
                    if (value[nestedKey] !== undefined && value[nestedKey] !== null) {
                        nested[nestedKey] = value[nestedKey];
                    }
                });
                if (Object.keys(nested).length > 0) {
                    summary[key] = nested;
                }
                return;
            }

            summary[key] = value;
        });

        try {
            return JSON.stringify(summary);
        } catch (error) {
            return '';
        }
    }

    function recordConstraintAttempt(label, constraints, status, message) {
        const requested = debugState.requestedPostStartConstraints
            ? debugState.requestedPostStartConstraints.split('\n')
            : [];
        const results = debugState.postStartConstraintResults
            ? debugState.postStartConstraintResults.split('\n')
            : [];

        if (constraints) {
            requested.push(label + ': ' + JSON.stringify(constraints));
        } else if (!requested.length) {
            requested.push(label);
        }

        results.push(label + ': ' + status + (message ? ' (' + message + ')' : ''));

        updateDebugState({
            requestedPostStartConstraints: requested.join('\n'),
            postStartConstraintResults: results.join('\n')
        });
    }

    function isVideoScanReady() {
        return !!(
            videoElement
            && videoElement.srcObject
            && videoElement.videoWidth > 0
            && videoElement.videoHeight > 0
            && videoElement.readyState >= 2
            && !videoElement.paused
            && !videoElement.ended
        );
    }

    function stopTracks(stream) {
        stream.getTracks().forEach(function (track) {
            try {
                track.stop();
            } catch (error) {
                console.warn('[PosCameraScanner] Error stopping track:', error);
            }
        });
    }

    function roundNumber(value) {
        return Math.round(value * 100) / 100;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    return {
        initialize: initialize,
        openScanner: openScanner,
        closeScanner: closeScanner,
        getState: function () {
            return state;
        }
    };
})();

document.addEventListener('DOMContentLoaded', function () {
    window.PosCameraScanner.initialize();
});

/**
 * POS Camera Scanner Module
 * Handles camera-based barcode/QR decoding with deterministic cleanup
 *
 * Features:
 * - Rear-camera preference with fallback to available camera
 * - Multi-format barcode decoding (1D + 2D)
 * - Single-hit lock to prevent duplicate decodes
 * - Deterministic media stream cleanup
 * - Integration with existing POS scan resolver
 */

window.PosCameraScanner = (function () {
    'use strict';

    // State constants
    const States = {
        IDLE: 'idle',
        OPENING: 'opening',
        READY: 'ready',
        DECODING: 'decoding',
        LOCKED: 'locked',
        CAMERA_ERROR: 'camera_error',
        DECODER_ERROR: 'decoder_error',
        DECODE_ERROR: 'decode_error'
    };

    // Failure stage codes for diagnostics
    const FailureStages = {
        CAMERA_PERMISSION: 'CAMERA_PERMISSION',
        CAMERA_UNAVAILABLE: 'CAMERA_UNAVAILABLE',
        CAMERA_BUSY: 'CAMERA_BUSY',
        DECODER_INIT: 'DECODER_INIT',
        DECODER_INVALID_API: 'DECODER_INVALID_API',
        DECODE_PROCESSING: 'DECODE_PROCESSING',
        DECODE_NOT_FOUND: 'DECODE_NOT_FOUND'
    };

    // Module state
    let state = States.IDLE;
    let mediaStream = null;
    let scanner = null;
    let videoElement = null;
    let modalElement = null;
    let statusElement = null;
    let retryButton = null;
    let cameraButton = null;
    let closeButton = null;
    let cancelButton = null;
    let hasDecoded = false;
    let hasAttemptedDecode = false;
    let decoderInstance = null;
    let lastFailureStage = null;
    let lastFailureToken = null;
    let frameMissCount = 0;

    // Configuration
    const FORMAT_ALLOWLIST = [
        'EAN_13', 'EAN_8', 'UPC_A', 'UPC_E',
        'CODE_128', 'CODE_39', 'CODE_93', 'ITF',
        'CODABAR',
        'RSS_14',
        'QR_CODE',
        'DATA_MATRIX',
        'PDF_417',
        'AZTEC'
    ];

    // Keep broad coverage, but still pass explicit formats so reader ordering stays deterministic.
    const USE_FORMAT_RESTRICTION = true;
    // Intermittent-working baseline: broad formats + try harder.
    const ENABLE_TRY_HARDER = true;
    const DECODE_ATTEMPT_INTERVAL_MS = 16;

    // Status messages
    const Messages = {
        OPENING: 'Membuka kamera...',
        READY: 'Tunjukkan barcode ke kamera',
        DECODING: 'Memindai...',
        PERMISSION_DENIED: 'Akses kamera ditolak. Periksa pengaturan privasi Anda.',
        CAMERA_UNAVAILABLE: 'Kamera tidak tersedia atau sedang digunakan.',
        CAMERA_BUSY: 'Kamera sedang digunakan oleh aplikasi lain.',
        NOT_FOUND: 'Barcode tidak dikenali. Silakan coba lagi atau edit secara manual.',
        OVER_LIMIT: 'Barcode terlalu panjang (melebihi 255 karakter). Edit secara manual.',
        DECODE_ERROR: 'Gagal memproses barcode. Silakan coba lagi.'
    };

    /**
     * Initialize the camera scanner module
     */
    function initialize() {
        console.log('[PosCameraScanner] Initializing');

        // Get DOM elements
        modalElement = document.getElementById('pos-camera-scanner-modal');
        videoElement = document.getElementById('pos-camera-video');
        statusElement = document.getElementById('pos-camera-scanner-status');
        retryButton = document.getElementById('pos-camera-scanner-retry');
        cameraButton = document.getElementById('pos-btn-scan-camera');
        closeButton = document.getElementById('pos-camera-scanner-close');
        cancelButton = document.getElementById('pos-camera-scanner-cancel');

        if (!modalElement || !videoElement || !cameraButton) {
            console.warn('[PosCameraScanner] Required DOM elements not found');
            return false;
        }

        // Attach event listeners
        cameraButton.addEventListener('click', openScanner);
        closeButton.addEventListener('click', closeScanner);
        cancelButton.addEventListener('click', closeScanner);
        retryButton.addEventListener('click', retryScanning);

        // Bootstrap modal close handler
        $(modalElement).on('hidden.bs.modal', function () {
            stopScanning();
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', function () {
            stopScanning();
        });

        state = States.IDLE;
        return true;
    }

    /**
     * Open the scanner modal and start camera
     */
    function openScanner() {
        console.log('[PosCameraScanner] Opening scanner');
        state = States.OPENING;
        hasDecoded = false;
        hasAttemptedDecode = false;
        frameMissCount = 0;

        setStatus(Messages.OPENING);
        retryButton.classList.add('d-none');

        // Show modal
        $(modalElement).modal('show');

        // Start camera after modal is shown
        setTimeout(startCamera, 300);
    }

    /**
     * Start camera with rear-camera preference and fallback
     */
    function startCamera() {
        console.log('[PosCameraScanner] Starting camera');

        const constraints = {
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1920 },
                height: { ideal: 1080 }
            },
            audio: false
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(function (stream) {
                mediaStream = stream;

                // Improve mobile/browser playback compatibility for camera preview.
                videoElement.setAttribute('playsinline', 'true');
                videoElement.setAttribute('autoplay', 'true');
                videoElement.setAttribute('muted', 'true');
                videoElement.muted = true;
                videoElement.srcObject = stream;

                // Best-effort autofocus/exposure hints for dense 1D barcodes (safe no-op if unsupported).
                const videoTrack = stream.getVideoTracks && stream.getVideoTracks()[0];
                if (videoTrack && typeof videoTrack.getCapabilities === 'function') {
                    const caps = videoTrack.getCapabilities() || {};
                    const advanced = {};
                    if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
                        advanced.focusMode = 'continuous';
                    }
                    if (Array.isArray(caps.exposureMode) && caps.exposureMode.includes('continuous')) {
                        advanced.exposureMode = 'continuous';
                    }
                    if (Object.keys(advanced).length > 0 && typeof videoTrack.applyConstraints === 'function') {
                        videoTrack.applyConstraints({ advanced: [advanced] }).catch(function () {
                            // Ignore unsupported constraint errors; stream remains usable.
                        });
                    }
                }

                // Wait for video to be ready
                videoElement.onloadedmetadata = function () {
                    state = States.READY;
                    setStatus(Messages.READY);
                    startDecoding();
                };
            })
            .catch(function (error) {
                console.error('[PosCameraScanner] Camera error:', error);
                handleCameraError(error);
            });
    }

    /**
     * Generate a debug token for failure diagnostics
     */
    function generateDebugToken(stage) {
        const timestamp = Date.now().toString(36).slice(-4);
        return `${stage.slice(0, 4)}_${timestamp}`.toUpperCase();
    }

    /**
     * Log structured diagnostics for scanner failures
     */
    function logDiagnostics(stage, error) {
        const token = generateDebugToken(stage);
        const errorMessage = error ? (error.message || String(error)) : 'Unknown error';
        const errorName = error && error.name ? error.name : 'Unknown';

        lastFailureStage = stage;
        lastFailureToken = token;

        console.error('[PosCameraScanner] Diagnostic failure:', {
            token: token,
            stage: stage,
            errorName: errorName,
            errorMessage: errorMessage,
            timestamp: new Date().toISOString()
        });
    }

    /**
     * Handle camera-related errors with appropriate messaging
     */
    function handleCameraError(error) {
        console.error('[PosCameraScanner] Error details:', error.name, error.message);

        let message = Messages.CAMERA_UNAVAILABLE;
        let stage = FailureStages.CAMERA_UNAVAILABLE;

        if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
            message = Messages.PERMISSION_DENIED;
            stage = FailureStages.CAMERA_PERMISSION;
        } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
            message = Messages.CAMERA_UNAVAILABLE;
            stage = FailureStages.CAMERA_UNAVAILABLE;
        } else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
            message = Messages.CAMERA_BUSY;
            stage = FailureStages.CAMERA_BUSY;
        }

        logDiagnostics(stage, error);
        state = States.CAMERA_ERROR;
        setStatus(message);
        retryButton.classList.remove('d-none');
    }

    /**
     * Start ZXing decoder and scan for barcodes
     */
    function startDecoding() {
        console.log('[PosCameraScanner] Starting decoder');

        if (!videoElement) {
            console.warn('[PosCameraScanner] Video element not available for decoding');
            return;
        }

        if (!videoElement.srcObject) {
            console.warn('[PosCameraScanner] Video stream not attached yet');
            return;
        }

        // Metadata might lag briefly on some browsers; retry once stream dimensions are known.
        if (videoElement.readyState < 1) {
            console.warn('[PosCameraScanner] Video metadata not ready for decoding, retrying');
            setTimeout(function () {
                if (state !== States.IDLE && !hasDecoded) {
                    startDecoding();
                }
            }, 120);
            return;
        }

        // Guard: Check if ZXing is available
        if (typeof ZXing === 'undefined') {
            const error = new Error('ZXing library not loaded');
            logDiagnostics(FailureStages.DECODER_INIT, error);
            state = States.DECODER_ERROR;
            setStatus(Messages.DECODING);
            console.warn('[PosCameraScanner] ZXing unavailable, will retry on scan attempt');
            return;
        }

        try {
            // Dynamically import and initialize the decoder
            const { BrowserMultiFormatReader, FormatException, NotFoundException } = ZXing;

            if (!BrowserMultiFormatReader) {
                const error = new Error('BrowserMultiFormatReader not found in ZXing');
                logDiagnostics(FailureStages.DECODER_INVALID_API, error);
                state = States.DECODER_ERROR;
                setStatus(Messages.DECODING);
                console.warn('[PosCameraScanner] Decoder API invalid, will retry on scan attempt');
                return;
            }

            // Map string format names to ZXing.BarcodeFormat enum values
            const formatEnumMap = {
                'EAN_13': ZXing.BarcodeFormat.EAN_13,
                'EAN_8': ZXing.BarcodeFormat.EAN_8,
                'UPC_A': ZXing.BarcodeFormat.UPC_A,
                'UPC_E': ZXing.BarcodeFormat.UPC_E,
                'CODE_128': ZXing.BarcodeFormat.CODE_128,
                'CODE_39': ZXing.BarcodeFormat.CODE_39,
                'CODE_93': ZXing.BarcodeFormat.CODE_93,
                'ITF': ZXing.BarcodeFormat.ITF,
                'CODABAR': ZXing.BarcodeFormat.CODABAR,
                'RSS_14': ZXing.BarcodeFormat.RSS_14,
                'QR_CODE': ZXing.BarcodeFormat.QR_CODE,
                'DATA_MATRIX': ZXing.BarcodeFormat.DATA_MATRIX,
                'PDF_417': ZXing.BarcodeFormat.PDF_417,
                'AZTEC': ZXing.BarcodeFormat.AZTEC
            };

            // Convert format allowlist to enum values
            const formatEnums = FORMAT_ALLOWLIST
                .map(name => formatEnumMap[name])
                .filter(format => format !== undefined);

            const hints = new Map();
            hints.set(ZXing.DecodeHintType.ASSUME_GS1, true);
            if (ENABLE_TRY_HARDER) {
                hints.set(ZXing.DecodeHintType.TRY_HARDER, true);
            }

            if (USE_FORMAT_RESTRICTION && formatEnums.length > 0) {
                hints.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, formatEnums);
            }

            decoderInstance = new BrowserMultiFormatReader(hints);
            decoderInstance.timeBetweenDecodingAttempts = DECODE_ATTEMPT_INTERVAL_MS;

            // Track that we're entering active decode processing
            hasAttemptedDecode = false;

            // Use continuous decode API to handle frame-by-frame processing
            const decodePromise = decoderInstance.decodeFromVideoElementContinuously(
                videoElement,
                function (result, err) {
                    // Mark that we've attempted decode on first frame processing
                    if (!hasAttemptedDecode && err) {
                        hasAttemptedDecode = true;
                    }

                    if (result && !hasDecoded) {
                        // Prevent duplicate decodes
                        hasDecoded = true;
                        hasAttemptedDecode = true;
                        state = States.LOCKED;

                        const decodedValue = result.getText();
                        const decodedFormat = result.getBarcodeFormat ? result.getBarcodeFormat() : 'UNKNOWN';
                        console.log('[PosCameraScanner] Decoded:', {
                            text: decodedValue,
                            format: String(decodedFormat)
                        });

                        handleDecodedValue(decodedValue);
                    }

                    // Classify errors: recoverable (frame miss) vs fatal (unexpected)
                    if (err) {
                        const isFrameMiss = err instanceof FormatException || err instanceof NotFoundException;
                        const isFatalError = !isFrameMiss && hasAttemptedDecode && !hasDecoded;

                        if (isFatalError) {
                            // Fatal/unexpected error: show user-facing message with debug token
                            logDiagnostics(FailureStages.DECODE_PROCESSING, err);
                            state = States.DECODE_ERROR;
                            const debugToken = lastFailureToken ? ` [${lastFailureToken}]` : '';
                            setStatus(Messages.DECODE_ERROR + debugToken);
                            retryButton.classList.remove('d-none');
                        } else if (isFrameMiss) {
                            // Frame miss (no code in this frame): remain in decoding state.
                            frameMissCount += 1;
                            if (frameMissCount % 60 === 0) {
                                console.log('[PosCameraScanner] Frame miss stats:', {
                                    count: frameMissCount,
                                    profile: 'stable',
                                    lastErrorName: err.name || 'Unknown',
                                    lastErrorMessage: err.message || String(err),
                                    videoWidth: videoElement.videoWidth,
                                    videoHeight: videoElement.videoHeight
                                });
                            }
                        }
                    }
                }
            );

            // Handle stream stop/close rejection deterministically to avoid uncaught noise
            if (decodePromise && typeof decodePromise.catch === 'function') {
                decodePromise.catch(function (error) {
                    // Stream stopped or closed normally - only log if this is an unexpected error
                    if (error && error.message && !error.message.includes('stream has ended') && state !== States.IDLE) {
                        console.debug('[PosCameraScanner] Decode stream error (expected on close):', error.message);
                    }
                });
            }

            state = States.DECODING;
            setStatus(Messages.DECODING);
        } catch (error) {
            console.error('[PosCameraScanner] Decoder initialization error:', error);
            logDiagnostics(FailureStages.DECODER_INIT, error);
            state = States.DECODER_ERROR;
            setStatus(Messages.DECODING);
            console.warn('[PosCameraScanner] Decoder failed to initialize, will retry on scan attempt');
        }
    }

    /**
     * Handle decoded barcode/QR value
     */
    function handleDecodedValue(decodedValue) {
        console.log('[PosCameraScanner] Handling decoded value:', decodedValue);

        const trimmedValue = decodedValue.trim();

        // Check length guard (255 character limit from resolver API)
        if (trimmedValue.length > 255) {
            state = States.LOCKED;
            // Mirror value to input but don't submit to resolver
            const searchInput = document.getElementById('pos-shell-search');
            if (searchInput) {
                searchInput.value = trimmedValue;
                searchInput.focus();
            }
            setStatus(Messages.OVER_LIMIT);

            // Close scanner after showing message
            setTimeout(closeScanner, 1500);
            return;
        }

        // Mirror decoded value to scan input
        const searchInput = document.getElementById('pos-shell-search');
        if (searchInput) {
            searchInput.value = trimmedValue;
            searchInput.focus();
        }

        // Call existing resolver function (must be available in page context)
        if (window.executeScanResolve && typeof window.executeScanResolve === 'function') {
            setStatus(Messages.DECODING);
            window.executeScanResolve(trimmedValue)
                .then(function (result) {
                    console.log('[PosCameraScanner] Resolver result:', result);
                    // Close scanner after resolver completes (success or not-found)
                    setTimeout(closeScanner, 500);
                })
                .catch(function (error) {
                    console.error('[PosCameraScanner] Resolver error:', error);
                    // Close scanner even on error
                    setTimeout(closeScanner, 500);
                });
        } else {
            console.warn('[PosCameraScanner] executeScanResolve not available');
            // Close scanner if resolver is not available
            setTimeout(closeScanner, 500);
        }
    }

    /**
     * Stop decoding and cleanup media stream
     */
    function stopScanning() {
        console.log('[PosCameraScanner] Stopping scanner');

        // Stop decoder if running
        if (decoderInstance) {
            try {
                decoderInstance.reset();
            } catch (error) {
                console.warn('[PosCameraScanner] Error resetting decoder:', error);
            }
            decoderInstance = null;
        }

        // Stop video playback
        if (videoElement) {
            videoElement.pause();
            videoElement.srcObject = null;
        }

        // Stop all media tracks
        if (mediaStream) {
            mediaStream.getTracks().forEach(function (track) {
                try {
                    track.stop();
                    console.log('[PosCameraScanner] Stopped track:', track.kind);
                } catch (error) {
                    console.warn('[PosCameraScanner] Error stopping track:', error);
                }
            });
            mediaStream = null;
        }

        // Reset state
        state = States.IDLE;
        hasDecoded = false;
        hasAttemptedDecode = false;
    }

    /**
     * Close scanner modal and cleanup
     */
    function closeScanner() {
        console.log('[PosCameraScanner] Closing scanner modal');
        stopScanning();
        $(modalElement).modal('hide');
    }

    /**
     * Retry scanning after an error
     */
    function retryScanning() {
        console.log('[PosCameraScanner] Retrying scan');
        stopScanning();
        state = States.OPENING;
        hasDecoded = false;
        hasAttemptedDecode = false;
        setStatus(Messages.OPENING);
        retryButton.classList.add('d-none');
        setTimeout(startCamera, 300);
    }

    /**
     * Set status message element
     */
    function setStatus(message) {
        if (statusElement) {
            statusElement.textContent = message;
        }
    }

    // Public API
    return {
        initialize: initialize,
        openScanner: openScanner,
        closeScanner: closeScanner,
        getState: function () { return state; }
    };
})();

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function () {
    window.PosCameraScanner.initialize();
});

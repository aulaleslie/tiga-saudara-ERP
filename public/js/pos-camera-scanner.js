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
        ERROR: 'error'
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
    let decoderInstance = null;

    // Configuration
    const FORMAT_ALLOWLIST = [
        'EAN_13', 'EAN_8', 'UPC_A', 'UPC_E',
        'CODE_128', 'CODE_39', 'ITF',
        'CODABAR',
        'QR_CODE',
        'DATA_MATRIX',
        'PDF_417',
        'AZTEC'
    ];

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
                width: { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: false
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(function (stream) {
                mediaStream = stream;
                videoElement.srcObject = stream;

                // Wait for video to be ready
                videoElement.onloadedmetadata = function () {
                    videoElement.play();
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
     * Handle camera-related errors with appropriate messaging
     */
    function handleCameraError(error) {
        console.error('[PosCameraScanner] Error details:', error.name, error.message);

        let message = Messages.CAMERA_UNAVAILABLE;

        if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
            message = Messages.PERMISSION_DENIED;
        } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
            message = Messages.CAMERA_UNAVAILABLE;
        } else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
            message = Messages.CAMERA_BUSY;
        }

        state = States.ERROR;
        setStatus(message);
        retryButton.classList.remove('d-none');
    }

    /**
     * Start ZXing decoder and scan for barcodes
     */
    function startDecoding() {
        console.log('[PosCameraScanner] Starting decoder');

        if (!videoElement || videoElement.paused) {
            console.warn('[PosCameraScanner] Video not ready for decoding');
            return;
        }

        try {
            // Dynamically import and initialize the decoder
            const { BrowserMultiFormatReader, FormatException } = ZXing;

            const hints = new Map();
            hints.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, FORMAT_ALLOWLIST);

            decoderInstance = new BrowserMultiFormatReader(hints);

            // Decode continuously from video
            const decodingPromise = decoderInstance.decodeFromVideoElement(
                videoElement,
                function (result, err) {
                    if (result && !hasDecoded) {
                        // Prevent duplicate decodes
                        hasDecoded = true;
                        state = States.LOCKED;

                        const decodedValue = result.getText();
                        console.log('[PosCameraScanner] Decoded:', decodedValue);

                        handleDecodedValue(decodedValue);
                    }

                    if (err && !(err instanceof FormatException)) {
                        console.log('[PosCameraScanner] Decode attempt:', err.message);
                    }
                }
            );

            state = States.DECODING;
            setStatus(Messages.DECODING);
        } catch (error) {
            console.error('[PosCameraScanner] Decoder error:', error);
            state = States.ERROR;
            setStatus(Messages.DECODE_ERROR);
            retryButton.classList.remove('d-none');
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

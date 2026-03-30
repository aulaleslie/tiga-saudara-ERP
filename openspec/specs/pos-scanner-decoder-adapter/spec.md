## ADDED Requirements

### Requirement: POS scanner decoder adapter SHALL select native BarcodeDetector when available
The decoder adapter MUST check for native `BarcodeDetector` API availability at initialization and use it as the primary decode backend when present. When `BarcodeDetector` is not available, the adapter MUST fall back to `html5-qrcode` as the decode backend.

#### Scenario: Native BarcodeDetector is available on Android Chrome
- **WHEN** the scanner initializes on a browser where `'BarcodeDetector' in window` is true
- **THEN** the adapter MUST use the native `BarcodeDetector` API for all decode operations and MUST NOT load or invoke the html5-qrcode decode path

#### Scenario: BarcodeDetector is unavailable falls back to html5-qrcode
- **WHEN** the scanner initializes on a browser where `'BarcodeDetector' in window` is false
- **THEN** the adapter MUST use `html5-qrcode` as the decode backend for all decode operations

#### Scenario: BarcodeDetector missing required formats triggers fallback
- **WHEN** the scanner initializes on a browser where `BarcodeDetector` is available but `BarcodeDetector.getSupportedFormats()` does not include all required scanner formats
- **THEN** the adapter MUST fall back to `html5-qrcode` for that session

### Requirement: Decoder adapter SHALL provide a unified start/stop interface to the scanner session
The decoder adapter MUST expose `start(videoElement, onDecode, onError)` and `stop()` methods that the scanner state machine calls in place of direct ZXing API usage. The `onDecode` callback MUST receive the raw decoded text string. The `onError` callback MUST receive error objects for fatal decode failures only — frame misses (no barcode in frame) MUST be silently ignored.

#### Scenario: Scanner state machine starts decoding via adapter
- **WHEN** the scanner transitions to READY state and calls adapter `start()`
- **THEN** the adapter MUST begin continuous decode attempts against the provided video element and invoke `onDecode` with raw text for each successful decode

#### Scenario: Scanner state machine stops decoding via adapter
- **WHEN** the scanner session ends or transitions to an error state and calls adapter `stop()`
- **THEN** the adapter MUST cease all decode activity, cancel any pending frame requests, and release decoder resources

#### Scenario: Frame misses are not propagated as errors
- **WHEN** a decode attempt finds no barcode in the current video frame
- **THEN** the adapter MUST NOT invoke the `onError` callback and MUST silently continue to the next decode attempt

### Requirement: Native BarcodeDetector path SHALL use throttled requestAnimationFrame decode loop
The native decode path MUST use `requestAnimationFrame` for frame timing, throttled to approximately 100ms intervals (10 decode attempts per second), to balance responsiveness with CPU efficiency on mobile devices.

#### Scenario: Decode loop runs at throttled rate
- **WHEN** the native decode loop is active
- **THEN** the adapter MUST invoke `BarcodeDetector.detect()` no more frequently than once per 100ms

#### Scenario: Decode loop pauses when tab is backgrounded
- **WHEN** the browser tab containing the scanner is backgrounded
- **THEN** the `requestAnimationFrame`-based decode loop MUST automatically pause and resume when the tab returns to the foreground

### Requirement: Decoder adapter SHALL map scanner format allowlist to each backend's format identifiers
The adapter MUST maintain a single canonical format allowlist and translate it to the format identifiers expected by each backend: lowercase strings for native `BarcodeDetector` (e.g., `'ean_13'`, `'qr_code'`) and `Html5QrcodeSupportedFormats` enum values for the html5-qrcode fallback.

#### Scenario: All scanner-supported formats are mapped for native backend
- **WHEN** the native backend initializes with the format allowlist
- **THEN** the `BarcodeDetector` instance MUST be created with all allowlisted formats mapped to their native lowercase identifiers

#### Scenario: All scanner-supported formats are mapped for fallback backend
- **WHEN** the fallback backend initializes with the format allowlist
- **THEN** the html5-qrcode decoder MUST be configured with all allowlisted formats mapped to their corresponding `Html5QrcodeSupportedFormats` values

### Requirement: html5-qrcode fallback path SHALL decode from the existing video stream
The html5-qrcode fallback MUST decode frames from the scanner's existing video element via canvas frame capture rather than managing its own camera lifecycle. The fallback MUST NOT open a separate camera stream or display its own video preview.

#### Scenario: Fallback decodes from existing video element
- **WHEN** the fallback decode loop captures a frame for decoding
- **THEN** the adapter MUST draw the current video frame to an offscreen canvas and pass it to `html5-qrcode` for decode processing

#### Scenario: Fallback does not open its own camera
- **WHEN** the fallback backend starts
- **THEN** the adapter MUST NOT call any html5-qrcode camera initialization methods and MUST rely solely on the scanner's existing `getUserMedia` stream

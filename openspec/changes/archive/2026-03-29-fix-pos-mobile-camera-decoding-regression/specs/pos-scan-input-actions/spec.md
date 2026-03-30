## MODIFIED Requirements

### Requirement: POS camera scan action SHALL decode one value and mirror it to scan input
The POS camera scan action MUST decode supported barcode and QR values from the active camera preview on supported mobile browsers, copy the decoded raw value to the existing scan input, and attempt resolver submission only when the value length does not exceed the resolver input limit. The camera decode path MUST preserve raw decoded text semantics for retail barcodes and QR values and MUST NOT enable GS1-specific assumptions unless a later requirement explicitly opts in.

#### Scenario: Mobile decode mirrors supported barcode value into existing scan input
- **WHEN** a supported mobile browser decodes a QR code, EAN-13 barcode, or CODE-128 barcode from the active POS camera preview
- **THEN** the system MUST write the raw decoded value into the existing barcode/serial input field before resolver handling and continue using the active camera session rules already in effect

#### Scenario: Over-limit decoded value is not auto-submitted
- **WHEN** decoded value length exceeds 255 characters
- **THEN** the system MUST keep the decoded value in the scan input, MUST NOT call scan resolver, and MUST show warning guidance for manual edit

### Requirement: POS camera scan action SHALL prefer rear camera with fallback
Camera startup MUST request an environment-facing camera when available, MUST fall back to another available camera when the environment-facing camera is unavailable, and MUST prefer a tablet-safe preview profile with ideal `1280x720` constraints for the POS scanner session.

#### Scenario: Mobile or tablet requests rear camera with 1280x720 profile
- **WHEN** scanner is opened on a device with an environment-facing camera
- **THEN** the system MUST request the environment-facing camera with ideal `1280x720` video constraints for scanning

#### Scenario: Laptop falls back to available webcam
- **WHEN** scanner is opened on a device without an environment-facing camera
- **THEN** the system MUST open an available camera device and allow scan attempts

## ADDED Requirements

### Requirement: POS camera scanner SHALL expose optional in-session diagnostics for mobile validation
When scanner debugging is explicitly enabled, the camera scanner modal MUST show an unobtrusive but readable diagnostics panel inside the active scanner session so mobile validation can proceed without browser devtools. The panel MUST include the current scanner state, whether a camera stream is attached, current `videoWidth` and `videoHeight`, selected track label when available, last decoded text, last decoded format, frame miss count, the last non-fatal decode error name and message, the last fatal error token and stage, and whether resolver submission is in flight.

#### Scenario: Debug panel is visible only when explicitly enabled
- **WHEN** the scanner modal opens with the debug flag disabled
- **THEN** the diagnostics panel MUST remain hidden from the standard cashier UI

#### Scenario: Debug panel reflects live scanner runtime data
- **WHEN** the scanner modal is open with the debug flag enabled
- **THEN** the diagnostics panel MUST update to reflect the current scanner runtime state and latest available decode and error diagnostics without requiring browser devtools

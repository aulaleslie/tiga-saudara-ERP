## ADDED Requirements

### Requirement: POS scan input SHALL provide deterministic helper action
The POS shell scan section MUST provide an explicit helper action control that triggers scan resolution without requiring keyboard Enter behavior.

#### Scenario: Manual tablet input uses helper action
- **WHEN** cashier types a barcode or serial into the POS scan input on a tablet browser and taps the helper action
- **THEN** the system MUST execute scan resolution for the typed value

#### Scenario: Empty input helper action is blocked with guidance
- **WHEN** cashier taps the helper action while the scan input is empty
- **THEN** the system MUST NOT call scan resolution and MUST show guidance to enter a barcode or serial first

### Requirement: Enter and helper action SHALL use equivalent scan resolution behavior
Keyboard Enter and helper-action triggers MUST follow the same scan resolution contract and status-feedback behavior for successful and failed resolution outcomes. Camera-decoded submissions MUST execute through the same resolver path so outcomes remain semantically aligned with existing triggers.

#### Scenario: Product exact match parity
- **WHEN** the same valid product barcode is submitted via Enter, helper action, and camera-decoded value
- **THEN** all submissions MUST produce equivalent cart-add behavior and success feedback semantics

#### Scenario: Not-found parity
- **WHEN** the same unknown code is submitted via Enter, helper action, and camera-decoded value
- **THEN** all submissions MUST produce equivalent not-found feedback semantics

### Requirement: POS scan section SHALL use professional action-rail layout
The POS scan section MUST render scan controls in a structured action rail with clear priority ordering so the layout remains tidy and operationally scannable.

#### Scenario: Action priority is visually clear
- **WHEN** the scan section is rendered
- **THEN** the direct scan helper action MUST be visually primary compared with secondary search-list and camera action controls

#### Scenario: Camera action is active in stable rail position
- **WHEN** the scan section is rendered after camera launch
- **THEN** the camera scan control MUST appear as an enabled action in the previously reserved stable rail position without structural rearrangement of other controls

### Requirement: Scan action rail SHALL remain usable in supported tablet landscape layouts
In supported POS tablet landscape layouts, scan input and actions MUST remain reachable and readable without overlap or clipping.

#### Scenario: Landscape layout keeps actions accessible
- **WHEN** POS sell shell is viewed in supported tablet landscape viewport
- **THEN** the scan input and action controls MUST remain fully visible with tappable controls

#### Scenario: Status feedback remains visible after scan action
- **WHEN** cashier triggers scan resolution from the action rail
- **THEN** scan status feedback MUST remain visible in the designated status area below the scan controls

## ADDED Requirements

### Requirement: POS camera scan action SHALL decode one value and mirror it to scan input
The POS camera scan action MUST decode supported barcode and QR values from the active camera preview on supported mobile browsers, copy the decoded raw value to the existing scan input, and attempt resolver submission only when the value length does not exceed the resolver input limit. The camera decode path MUST preserve raw decoded text semantics for retail barcodes and QR values and MUST NOT enable GS1-specific assumptions unless a later requirement explicitly opts in.

#### Scenario: Mobile decode mirrors supported barcode value into existing scan input
- **WHEN** a supported mobile browser decodes a QR code, EAN-13 barcode, or CODE-128 barcode from the active POS camera preview
- **THEN** the system MUST write the raw decoded value into the existing barcode/serial input field before resolver handling and continue using the active camera session rules already in effect

#### Scenario: Over-limit decoded value is not auto-submitted
- **WHEN** decoded value length exceeds 255 characters
- **THEN** the system MUST keep the decoded value in the scan input, MUST NOT call scan resolver, and MUST show warning guidance for manual edit

### Requirement: POS camera scan session SHALL remain open across accepted scans until cashier exits
The camera scan modal MUST remain open after successful, not-found, and retryable error outcomes so tablet cashiers can continue scanning multiple products in sequence. The session MUST stop camera stream and close only when the cashier explicitly exits the scanner or the session cannot continue due to unrecoverable camera initialization failure.

#### Scenario: Successful decode keeps scanner session active
- **WHEN** a decoded value resolves to a product or serial match during an active camera scan session
- **THEN** the system MUST complete the existing success flow, keep the scanner modal open, and return the session to a ready state for the next scan

#### Scenario: Not-found outcome stays in session for immediate retry
- **WHEN** a decoded value resolves as not found during an active camera scan session
- **THEN** the system MUST keep the scanner modal open, preserve the decoded value in the existing scan input, show not-found guidance, and allow the cashier to continue scanning without reopening the scanner

#### Scenario: Cashier-controlled exit ends the session
- **WHEN** the cashier activates the scanner close action
- **THEN** the system MUST stop decode processing, release the camera stream, and close the scanner modal

### Requirement: POS camera scan action SHALL prefer rear camera with fallback
Camera startup MUST request an environment-facing camera when available, MUST fall back to another available camera when the environment-facing camera is unavailable, and MUST prefer a tablet-safe preview profile with ideal `1280x720` constraints for the POS scanner session.

#### Scenario: Mobile or tablet requests rear camera with 1280x720 profile
- **WHEN** scanner is opened on a device with an environment-facing camera
- **THEN** the system MUST request the environment-facing camera with ideal `1280x720` video constraints for scanning

#### Scenario: Laptop falls back to available webcam
- **WHEN** scanner is opened on a device without an environment-facing camera
- **THEN** the system MUST open an available camera device and allow scan attempts

### Requirement: POS continuous camera scanning SHALL emulate discrete hardware scanner submissions
During an active camera scan session, the system MUST accept only one decoded value at a time for resolver submission, MUST suppress duplicate submissions caused by repeated frame detections, and MUST automatically re-arm the session after each accepted scan outcome so the camera behaves like a virtual scanner device.

#### Scenario: In-flight submission blocks additional accepted scans
- **WHEN** a decoded value has been accepted and the shared scan resolver is still processing it
- **THEN** the system MUST ignore further decode hits until the current resolver submission finishes

#### Scenario: Same code is not re-submitted immediately from repeated frames
- **WHEN** the same decoded value appears repeatedly within the configured duplicate-suppression window
- **THEN** the system MUST treat those repeated detections as one accepted scan and MUST NOT submit duplicate resolver requests for that value

#### Scenario: Different next item can be scanned after re-arm
- **WHEN** a prior accepted scan outcome completes and the short re-arm cooldown elapses
- **THEN** the system MUST return the session to a ready scanning state so the next distinct product barcode can be accepted without reopening the scanner

### Requirement: POS continuous camera scan session SHALL provide persistent in-session guidance and outcome feedback
The mobile camera scanner surface MUST show scanner readiness, accepted-scan feedback, warnings, and retry guidance inside the active session so cashiers can keep working without relying on modal churn for feedback. The scanner surface MUST include a visible scan guide appropriate for repeated barcode scanning on tablets.

#### Scenario: Ready state guides cashier aim
- **WHEN** the camera preview becomes ready for scanning
- **THEN** the system MUST show in-session guidance indicating that the cashier can aim a barcode within the scan guide and begin scanning immediately

#### Scenario: Accepted scan feedback is shown without closing session
- **WHEN** a decoded value is accepted for resolver submission
- **THEN** the scanner surface MUST show immediate in-session feedback that the scan was captured while keeping the session active

#### Scenario: Retryable failure is communicated in session
- **WHEN** the scanner encounters a retryable decode, not-found, or resolver failure during an active session
- **THEN** the system MUST show the warning or error state inside the scanner session and allow the cashier to retry without reopening the scanner

### Requirement: POS camera scanner SHALL expose optional in-session diagnostics for mobile validation
When scanner debugging is explicitly enabled, the camera scanner modal MUST show an unobtrusive but readable diagnostics panel inside the active scanner session so mobile validation can proceed without browser devtools. The panel MUST include the current scanner state, whether a camera stream is attached, current `videoWidth` and `videoHeight`, selected track label when available, last decoded text, last decoded format, frame miss count, the last non-fatal decode error name and message, the last fatal error token and stage, and whether resolver submission is in flight.

#### Scenario: Debug panel is visible only when explicitly enabled
- **WHEN** the scanner modal opens with the debug flag disabled
- **THEN** the diagnostics panel MUST remain hidden from the standard cashier UI

#### Scenario: Debug panel reflects live scanner runtime data
- **WHEN** the scanner modal is open with the debug flag enabled
- **THEN** the diagnostics panel MUST update to reflect the current scanner runtime state and latest available decode and error diagnostics without requiring browser devtools

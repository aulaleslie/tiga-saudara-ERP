## MODIFIED Requirements

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

## ADDED Requirements

### Requirement: POS camera scan action SHALL decode one value and mirror it to scan input
The POS camera scan action MUST decode supported barcode/QR values from camera preview, copy the decoded raw value to the existing scan input, and attempt resolver submission only when the value length does not exceed the resolver input limit.

#### Scenario: Decoded value is mirrored to existing scan input
- **WHEN** a camera scan successfully decodes a value
- **THEN** the system MUST write the raw decoded value into the existing barcode/serial input field before resolver handling

#### Scenario: Over-limit decoded value is not auto-submitted
- **WHEN** decoded value length exceeds 255 characters
- **THEN** the system MUST keep the decoded value in the scan input, MUST NOT call scan resolver, and MUST show warning guidance for manual edit

### Requirement: POS camera scan session SHALL close after first decode outcome
The camera scan modal MUST end each session after the first decode handling result to prevent duplicate adds and preserve deterministic cashier flow.

#### Scenario: First decode with resolvable value closes scanner
- **WHEN** first decoded value resolves to product or serial match
- **THEN** the system MUST stop camera stream, close scanner modal, and complete existing success flow

#### Scenario: First decode with not-found value closes scanner but preserves review
- **WHEN** first decoded value resolves as not found
- **THEN** the system MUST stop camera stream, close scanner modal, keep decoded value in scan input, and show not-found guidance for manual correction

### Requirement: POS camera scan action SHALL prefer rear camera with fallback
Camera startup MUST request environment-facing camera when available and fall back to another available camera when environment camera is unavailable.

#### Scenario: Mobile or tablet selects rear camera when available
- **WHEN** scanner is opened on a device with an environment-facing camera
- **THEN** the system MUST attempt to open the environment-facing camera for scanning

#### Scenario: Laptop falls back to available webcam
- **WHEN** scanner is opened on a device without an environment-facing camera
- **THEN** the system MUST open an available camera device and allow scan attempts

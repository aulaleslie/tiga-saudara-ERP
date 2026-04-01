# scan-acknowledge-gate Specification

## Purpose
TBD - created by archiving change pos-scan-acknowledge-gate. Update Purpose after archive.
## Requirements
### Requirement: Scanner pauses after each scan result

After the camera scanner decodes a barcode and the scan resolve completes (any outcome), the scanner SHALL enter a PAUSED state. While paused, the scanner MUST NOT decode new barcodes. The status panel SHALL display the scan result and a dismiss button labeled "Lanjutkan Scan ▶".

#### Scenario: Product found — scanner pauses with product name
- **WHEN** the camera scanner decodes a barcode that resolves to `product_exact`
- **THEN** the status panel SHALL display `Produk "<product_name>" telah ditambahkan` with `accepted` tone
- **AND** the dismiss button SHALL be visible
- **AND** the scanner SHALL NOT decode further barcodes until the button is clicked

#### Scenario: Serial found — scanner pauses with serial number
- **WHEN** the camera scanner decodes a barcode that resolves to `serial_exact`
- **THEN** the status panel SHALL display `Serial "<serial_number>" telah ditambahkan` with `accepted` tone
- **AND** the dismiss button SHALL be visible
- **AND** the scanner SHALL NOT decode further barcodes until the button is clicked

#### Scenario: Code not found — scanner pauses with scanned code
- **WHEN** the camera scanner decodes a barcode that resolves to `not_found`
- **THEN** the status panel SHALL display `Kode "<scanned_value>" tidak ditemukan` with `warning` tone
- **AND** the dismiss button SHALL be visible
- **AND** the scanner SHALL NOT decode further barcodes until the button is clicked

#### Scenario: Resolver error — scanner pauses with error
- **WHEN** the camera scanner decodes a barcode and the resolver fails with an error
- **THEN** the status panel SHALL display the error message with `error` tone
- **AND** the dismiss button SHALL be visible
- **AND** the scanner SHALL NOT decode further barcodes until the button is clicked

### Requirement: Dismiss button resumes scanning

The dismiss button SHALL transition the scanner from PAUSED to COOLDOWN, then to READY. After the transition, the scanner SHALL resume decoding barcodes.

#### Scenario: User clicks dismiss to continue scanning
- **WHEN** the scanner is in PAUSED state and the user clicks "Lanjutkan Scan ▶"
- **THEN** the dismiss button SHALL be hidden
- **AND** the scanner SHALL transition through COOLDOWN to READY state
- **AND** the scanner SHALL resume barcode decoding

#### Scenario: User closes modal while paused
- **WHEN** the scanner is in PAUSED state and the user closes the camera modal
- **THEN** the session SHALL stop cleanly (camera released, state reset to IDLE)
- **AND** no errors SHALL occur

### Requirement: Camera displays correctly on modal reopen

The camera scanner SHALL wait for the Bootstrap modal to be fully visible before starting the camera pipeline. The html5-qrcode fallback cleanup SHALL be properly awaited before resources are released.

#### Scenario: Close and reopen camera scanner modal
- **WHEN** the user closes the camera scanner modal and reopens it
- **THEN** the camera video feed SHALL be visible in the modal
- **AND** the scanner SHALL reach READY state and begin decoding

#### Scenario: Rapid close and reopen
- **WHEN** the user closes the camera modal and immediately reopens it
- **THEN** the scanner SHALL wait for the modal show animation to complete before starting the camera
- **AND** the previous session's camera resources SHALL be fully released

### Requirement: Manual barcode input is unaffected

The scan acknowledgment gate SHALL only apply to camera-based scanning. Manual barcode input via the search field (Enter key or helper button) SHALL continue to add products immediately without a dialog.

#### Scenario: Manual barcode entry via Enter key
- **WHEN** the user types a barcode in the search input and presses Enter
- **THEN** the product SHALL be added to cart immediately without a pause dialog
- **AND** behavior SHALL be identical to the current implementation


## ADDED Requirements

### Requirement: Decoder stays active after successful scan
The camera decoder (both native BarcodeDetector and html5-qrcode fallback) SHALL remain active and scanning after a barcode is successfully decoded and processed by the resolver. The system MUST NOT stop or restart the decoder between scans within the same scanner session.

#### Scenario: Native backend continuous scanning
- **WHEN** a barcode is decoded by the native BarcodeDetector rAF loop and the resolver returns a result (product_exact, serial_exact, or not_found)
- **THEN** the rAF decode loop SHALL continue running without being cancelled and restarted
- **AND** the BarcodeDetector instance SHALL remain the same instance (not destroyed and recreated)

#### Scenario: Fallback backend continuous scanning
- **WHEN** a barcode is decoded by the html5-qrcode fallback and the resolver returns a result
- **THEN** the html5-qrcode instance SHALL remain running with its camera stream active
- **AND** the html5-qrcode instance SHALL NOT be stopped or set to null

#### Scenario: Second scan after first scan
- **WHEN** a first barcode is scanned and resolved, and a second different barcode is presented within the camera scan lane
- **THEN** the second barcode SHALL be decoded and processed by the resolver after the cooldown period expires
- **AND** the camera preview SHALL remain visible throughout both scans

### Requirement: Duplicate suppression remains effective during continuous scanning
The existing duplicate suppression mechanisms SHALL continue to prevent re-processing the same barcode during SUBMITTING, COOLDOWN, and within the `SAME_CODE_SUPPRESSION_MS` window.

#### Scenario: Same barcode presented during cooldown
- **WHEN** the same barcode remains in view of the camera during the COOLDOWN state after a scan
- **THEN** the system SHALL NOT re-process the barcode
- **AND** the system SHALL NOT call `executeScanResolve` again for the same code within `SAME_CODE_SUPPRESSION_MS` (1800ms)

#### Scenario: Same barcode presented during submission
- **WHEN** the decoder detects the same barcode while `submissionInFlight` is true
- **THEN** `handleDecodedValue` SHALL return early without calling the resolver

### Requirement: Scanner session cleanup unchanged
The full session cleanup (stopping decoder, releasing camera stream) SHALL still occur when the scanner modal is closed or the session ends.

#### Scenario: Close scanner after multiple scans
- **WHEN** the cashier clicks "Selesai Scan" or closes the scanner modal after scanning multiple items
- **THEN** the decoder SHALL be stopped, the camera stream released, and all state reset

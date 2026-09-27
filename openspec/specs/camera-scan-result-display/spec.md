# camera-scan-result-display Specification

## Purpose
TBD - created by archiving change fix-camera-scanner-post-scan. Update Purpose after archive.
## Requirements
### Requirement: Scanned code displayed in scan result message
After the resolver processes a scanned barcode, the scanner dialog session status SHALL display the scanned barcode value alongside the outcome message.

#### Scenario: Product found by barcode scan
- **WHEN** a barcode is scanned and the resolver returns `product_exact`
- **THEN** the session status detail SHALL include the scanned barcode value (e.g., "Kode 8901234567890 — Produk ditambahkan ke keranjang.")
- **AND** the status chip SHALL show "Tertangkap"

#### Scenario: Serial number found by scan
- **WHEN** a barcode is scanned and the resolver returns `serial_exact`
- **THEN** the session status detail SHALL include the scanned code value (e.g., "Kode SN12345 — Serial berhasil ditambahkan.")
- **AND** the status chip SHALL show "Tertangkap"

#### Scenario: Code not found
- **WHEN** a barcode is scanned and the resolver returns `not_found`
- **THEN** the session status detail SHALL include the scanned code value (e.g., "Kode 8901234567890 — Kode tidak ditemukan.")
- **AND** the status chip SHALL show "Tidak Ditemukan"

#### Scenario: Resolver error
- **WHEN** a barcode is scanned and the resolver returns an error or the request fails
- **THEN** the session status detail SHALL include the scanned code value
- **AND** the status chip SHALL show "Gagal"

### Requirement: Scan result persists until next scan
The scan result message (including the barcode value and outcome) SHALL remain visible in the scanner dialog until the next barcode is decoded. The system MUST NOT overwrite the result with a generic "Ready" message after the cooldown.

#### Scenario: Result stays visible after cooldown
- **WHEN** a barcode is scanned, resolved, and the 450ms cooldown completes
- **THEN** the session status SHALL still display the previous scan's result (code + outcome)
- **AND** the scanner state SHALL transition to READY (allowing new scans)
- **AND** the session status SHALL NOT be replaced with "Scanner siap untuk item berikutnya"

#### Scenario: Result replaced by next scan
- **WHEN** a scan result is displayed and a new different barcode is decoded
- **THEN** the session status SHALL update to show the new scan's "Submitting" message (with the new code)
- **AND** the previous result SHALL no longer be visible

### Requirement: Initial ready message on scanner open
When the scanner session first starts (or after a retry), the generic "Ready" message SHALL be shown as before. The "preserve result" behavior only applies after at least one scan has occurred.

#### Scenario: First open shows ready message
- **WHEN** the scanner modal is opened and the camera becomes ready
- **THEN** the session status SHALL display "Scanner siap untuk item berikutnya" with the "Siap" chip


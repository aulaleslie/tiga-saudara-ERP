## ADDED Requirements

### Requirement: Cash pickup amount input displays with thousand separators
The POS cash pickup modal's "Jumlah Pengambilan" amount input SHALL be a text input that formats the entered digits with Indonesian thousand separators as the cashier types, matching the display style of the staged multi-payment "Jumlah Pembayaran (Rp)" input. The input SHALL accept digit entry only (non-digit characters SHALL be stripped) and SHALL NOT support decimal amounts.

#### Scenario: Formatting applied while typing
- **WHEN** the cashier types `150000` into the cash pickup amount field
- **THEN** the field SHALL display `150.000`

#### Scenario: Non-digit characters are stripped
- **WHEN** the cashier types or pastes a value containing letters or symbols (e.g. `1a5b0`)
- **THEN** the field SHALL retain only the digit characters and display them formatted (e.g. `15`)

#### Scenario: Empty input displays as empty
- **WHEN** the cash pickup amount field has no digits entered
- **THEN** the field SHALL display as empty (not `0` or `Rp 0`)

### Requirement: Raw numeric amount is correctly derived from formatted display
The system SHALL derive the numeric pickup amount from the underlying raw digit value (not by parsing the formatted display string), at every point the amount is read: live validation of the "Lanjut" button, the step transition from amount entry to supervisor/OTP entry, and the final pickup submission.

#### Scenario: Validation uses the correct numeric amount
- **WHEN** the cashier enters a formatted amount of `150.000` and the live expected cash is `200000`
- **THEN** the "Lanjut" button SHALL be enabled, using the numeric value `150000` (not a value derived from misparsing the formatted string)

#### Scenario: Submitted amount matches the displayed formatted amount
- **WHEN** the cashier enters `150.000` and completes the pickup flow (supervisor + OTP) and confirms
- **THEN** the amount submitted to the server SHALL be `150000`

#### Scenario: Confirmation step displays the correct amount
- **WHEN** the cashier enters `1.500.000` and advances past the amount-entry step
- **THEN** the confirmation display SHALL show the pickup amount as `Rp 1.500.000` (derived from the raw numeric value `1500000`)

### Requirement: Amount input resets cleanly between pickup attempts
When the cash pickup modal is opened or closed/reset, both the displayed formatted value and the underlying raw numeric value SHALL be cleared together, so no stale amount carries over to the next pickup attempt.

#### Scenario: Reopening the modal shows an empty amount
- **WHEN** the cashier previously entered an amount, closed the modal without submitting, and reopens the "Pengambilan Kas" modal
- **THEN** the amount input SHALL display empty
- **AND** the underlying raw numeric value SHALL be cleared (not retained from the prior attempt)

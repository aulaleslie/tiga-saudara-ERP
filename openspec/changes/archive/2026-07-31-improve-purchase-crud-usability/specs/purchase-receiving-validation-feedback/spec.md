## ADDED Requirements

### Requirement: Receiving form displays location validation feedback
The purchase receiving form SHALL visibly display a Bahasa Indonesia validation error at the location control and in a validation summary when a receiving submission omits a location.

#### Scenario: Missing location is rejected visibly
- **WHEN** a user submits a receiving with one or more positive quantities but no location
- **THEN** the system SHALL reject the receiving without creating a receiving note
- **AND** it SHALL display `Lokasi wajib dipilih.` at the location control and in the validation summary
- **AND** it SHALL preserve the submitted quantities, notes, serials, and delivery number

### Requirement: Receiving form displays zero-quantity validation feedback
The purchase receiving form SHALL visibly display a Bahasa Indonesia validation error when every received quantity is zero or blank, both before normal client submission and after a server-side rejected request.

#### Scenario: Browser-side zero quantity attempt
- **WHEN** a user activates the receiving confirmation with every quantity zero or blank
- **THEN** the form SHALL remain unsubmitted
- **AND** it SHALL visibly display `Minimal satu produk harus memiliki jumlah diterima lebih dari 0.` in the validation summary

#### Scenario: Server-side zero quantity attempt
- **WHEN** a receiving request reaches the server with every quantity zero or blank
- **THEN** the system SHALL reject the request without creating a receiving note
- **AND** it SHALL display `Minimal satu produk harus memiliki jumlah diterima lebih dari 0.` after redirect
- **AND** it SHALL preserve submitted form values


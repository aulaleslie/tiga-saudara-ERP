## ADDED Requirements

### Requirement: Live expected cash fetch on modal open

When the POS cash pickup modal opens, the system SHALL fetch the current expected cash from the server via `GET /pos/sessions/{id}/summary` (with `Accept: application/json`) instead of reading the stale `data-expected-cash` DOM attribute. The session ID SHALL be read from the stable `data-session-id` attribute. While the fetch is in progress, the modal SHALL display a loading indicator in the expected cash field and disable the amount input and "Lanjut" button.

#### Scenario: Expected cash is fetched live on modal open
- **WHEN** the cashier clicks "Pengambilan Kas" to open the cash pickup modal
- **THEN** the system SHALL make an AJAX request to the session summary endpoint
- **AND** the expected cash field SHALL show a loading indicator while the request is in progress
- **AND** the amount input and "Lanjut" button SHALL be disabled until the fetch completes

#### Scenario: Live fetch succeeds with updated amount
- **WHEN** the session summary endpoint returns successfully
- **THEN** the expected cash field SHALL display the `expected_cash_total` from the response
- **AND** the amount input SHALL be enabled with its max validation set to the live expected cash value
- **AND** the "Lanjut" button validation SHALL use the live expected cash value

#### Scenario: Live fetch fails gracefully
- **WHEN** the session summary fetch fails (network error, server error)
- **THEN** the modal SHALL display an error message "Gagal memuat data kas. Coba lagi."
- **AND** the amount input SHALL remain disabled
- **AND** a "Coba lagi" (retry) option SHALL be available

#### Scenario: Expected cash reflects transactions after page load
- **WHEN** the POS page was loaded with expected cash of Rp 500.000
- **AND** three cash sales totaling Rp 700.000 have been completed since page load
- **AND** the cashier opens the cash pickup modal
- **THEN** the expected cash SHALL display Rp 1.200.000 (the live calculated value)
- **AND** the cashier SHALL be able to enter a pickup amount up to Rp 1.200.000

# pos-cash-pickup-live-expected Specification

## Purpose
TBD - created by archiving change pos-cash-pickup-otp-supervisor. Update Purpose after archive.

## Requirements

### Requirement: Live expected cash fetch on modal open

When a user with `pos.sessions.viewExpectedCash` opens the POS cash pickup modal, the system SHALL fetch the current expected cash from the server via `GET /pos/sessions/{id}/summary` (with `Accept: application/json`) instead of reading the stale `data-expected-cash` DOM attribute. The session ID SHALL be read from the stable `data-session-id` attribute. While the fetch is in progress, the modal SHALL display a loading indicator in the expected cash field and disable the amount input and "Lanjut" button.

#### Scenario: Expected cash is fetched live on modal open
- **WHEN** a cashier with balance visibility clicks "Pengambilan Kas" to open the cash pickup modal
- **THEN** the system SHALL make an AJAX request to the session summary endpoint
- **AND** the expected cash field SHALL show a loading indicator while the request is in progress
- **AND** the amount input and "Lanjut" button SHALL be disabled until the fetch completes

#### Scenario: Live fetch succeeds with updated amount
- **WHEN** the session summary endpoint returns successfully
- **THEN** the authorized viewer's expected cash field SHALL display the `expected_cash_total` from the response
- **AND** the amount input SHALL be enabled without any maximum based on expected cash
- **AND** the "Lanjut" button SHALL validate a positive amount without an expected-cash cap

#### Scenario: Live fetch fails gracefully
- **WHEN** the session summary fetch fails (network error, server error)
- **THEN** the modal SHALL display an error message "Gagal memuat data kas. Coba lagi."
- **AND** the amount input SHALL remain disabled
- **AND** a "Coba lagi" (retry) option SHALL be available

#### Scenario: Expected cash reflects transactions after page load
- **WHEN** the POS page was loaded with expected cash of Rp 500.000
- **AND** three cash sales totaling Rp 700.000 have been completed since page load
- **AND** a cashier with balance visibility opens the cash pickup modal
- **THEN** the expected cash SHALL display Rp 1.200.000 (the live calculated value)
- **AND** the cashier SHALL be able to enter a pickup amount including amounts exceeding Rp 1.200.000

#### Scenario: Pickup without balance visibility
- **WHEN** a user without `pos.sessions.viewExpectedCash` opens the pickup modal
- **THEN** the modal SHALL permit positive amount entry without fetching or requiring expected cash
- **AND** the existing supervisor OTP step SHALL remain available

### Requirement: Approved pickup amounts are not capped by expected cash
A positive pickup SHALL NOT be rejected because it exceeds expected cash or because the current or resulting balance is negative. The same existing supervisor OTP approval SHALL apply without an extra overdraft permission or confirmation. Existing session, business, amount, and approval validation SHALL remain effective.

#### Scenario: Pickup exceeds positive balance
- **WHEN** expected cash is Rp950000 and an otherwise valid OTP-approved pickup of Rp1000000 is submitted
- **THEN** the pickup SHALL succeed and record one Rp1000000 outflow
- **AND** ledger and cached expected cash SHALL become -Rp50000

#### Scenario: Pickup from zero or negative balance
- **WHEN** an otherwise valid approved positive pickup is submitted with expected cash zero or negative
- **THEN** the pickup SHALL succeed and reduce the signed balance by its full amount

#### Scenario: Invalid amount or approval
- **WHEN** a pickup has a non-positive or malformed amount, invalid OTP, or an ineligible session
- **THEN** the existing validation SHALL reject it without recording the pickup cash event

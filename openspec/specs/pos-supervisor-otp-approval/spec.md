# pos-supervisor-otp-approval Specification

## Purpose
TBD - created by archiving change pos-cash-pickup-otp-supervisor. Update Purpose after archive.
## Requirements
### Requirement: Supervisor search endpoint returns TOTP-enabled eligible supervisors

The system SHALL provide a `GET /pos/sell/supervisors/search` endpoint that returns users eligible to approve POS cash pickup actions. The endpoint SHALL accept a `q` query parameter for filtering by name or email, and a `limit` parameter (default 10). Only users matching ALL of the following criteria SHALL be returned: `is_active = true`, same `setting_id` as the requesting user (Super Admin bypasses setting check), `two_factor_secret IS NOT NULL`, `two_factor_confirmed_at IS NOT NULL`, has permission `pos.safeDrops.approve`, has permission `pos.supervisor.approval`. The endpoint SHALL require authentication and `pos.access` permission.

#### Scenario: Search returns matching TOTP-enabled supervisors
- **WHEN** an authenticated POS user sends `GET /pos/sell/supervisors/search?q=ahmad`
- **THEN** the system SHALL return a JSON response with `results` array containing users whose name or email matches "ahmad"
- **AND** each result SHALL include `id`, `name`, and `email` fields
- **AND** all returned users SHALL have TOTP enabled and confirmed
- **AND** all returned users SHALL have `pos.safeDrops.approve` and `pos.supervisor.approval` permissions

#### Scenario: Search with empty query returns all eligible supervisors
- **WHEN** an authenticated POS user sends `GET /pos/sell/supervisors/search` without a `q` parameter or with empty `q`
- **THEN** the system SHALL return all eligible supervisors (up to the limit)

#### Scenario: No eligible supervisors found
- **WHEN** an authenticated POS user sends `GET /pos/sell/supervisors/search?q=nonexistent`
- **THEN** the system SHALL return `{"results": []}`

#### Scenario: Supervisors without TOTP are excluded
- **WHEN** a user has `pos.safeDrops.approve` and `pos.supervisor.approval` permissions but does NOT have `two_factor_confirmed_at` set
- **THEN** that user SHALL NOT appear in the supervisor search results

### Requirement: Supervisor searchable dropdown in cash pickup modal

The POS cash pickup modal Step 2 SHALL present a searchable text input for selecting a supervisor instead of an email field. The dropdown SHALL follow the same vanilla JS pattern as the POS customer search: debounced input (250ms) triggering a fetch to the supervisor search endpoint, results rendered as clickable list items. Once a supervisor is selected, the input SHALL be replaced with the selected supervisor's name and a clear button. The OTP code input SHALL only become active after a supervisor is selected.

#### Scenario: Cashier searches for supervisor by name
- **WHEN** the cashier types "ahmad" in the supervisor search input
- **THEN** the system SHALL display a dropdown of matching supervisors after 250ms debounce
- **AND** each result SHALL show the supervisor's name and email

#### Scenario: Cashier selects a supervisor
- **WHEN** the cashier clicks on a supervisor in the dropdown results
- **THEN** the search input SHALL be replaced with the selected supervisor's name and a clear button
- **AND** the OTP code input SHALL become enabled/focused
- **AND** the selected supervisor's ID SHALL be stored for form submission

#### Scenario: Cashier clears supervisor selection
- **WHEN** the cashier clicks the clear button on the selected supervisor
- **THEN** the search input SHALL reappear empty
- **AND** the OTP code input SHALL become disabled
- **AND** the stored supervisor ID SHALL be cleared

#### Scenario: No eligible supervisors available
- **WHEN** the supervisor search returns zero results for any query
- **THEN** the UI SHALL display the message "Tidak ada supervisor dengan OTP aktif."

### Requirement: OTP-based supervisor approval for cash pickup

The system SHALL verify the supervisor's identity using a 6-digit TOTP code via `TwoFactorService::verifyCode()` instead of password verification. The `POST /pos/sessions/{session}/pickup` endpoint SHALL accept `supervisor_id` (integer) and `otp_code` (string, 6 digits) instead of `supervisor_email` and `supervisor_password`. The `PosSupervisorApprovalService` SHALL have a new method `approveSafeDropWithOtp()` that looks up the supervisor by ID, runs the same eligibility checks (active, setting, permissions), and verifies the TOTP code.

#### Scenario: Successful cash pickup with valid OTP
- **WHEN** the cashier submits a cash pickup with a valid `supervisor_id` and correct `otp_code`
- **THEN** the system SHALL approve the safe drop
- **AND** the system SHALL create a `PosSupervisorApproval` record with result APPROVED
- **AND** the system SHALL return `expected_cash_after` in the response

#### Scenario: Cash pickup with invalid OTP code
- **WHEN** the cashier submits a cash pickup with a valid `supervisor_id` but incorrect `otp_code`
- **THEN** the system SHALL return HTTP 422 with error message "Kode OTP tidak valid."
- **AND** the safe drop SHALL NOT be created

#### Scenario: Cash pickup with supervisor who has no TOTP
- **WHEN** the cashier submits a cash pickup with a `supervisor_id` for a user without TOTP enabled
- **THEN** the system SHALL return HTTP 422 with error message "Supervisor belum mengaktifkan OTP."

#### Scenario: Cash pickup with non-existent supervisor
- **WHEN** the cashier submits a cash pickup with a `supervisor_id` that does not match any user
- **THEN** the system SHALL return HTTP 422 with error message "Supervisor tidak ditemukan."

#### Scenario: Confirm button requires both supervisor and OTP
- **WHEN** the cashier is on Step 2 of the cash pickup modal
- **THEN** the "Konfirmasi Pengambilan" button SHALL be disabled until both a supervisor is selected AND a 6-digit OTP code is entered


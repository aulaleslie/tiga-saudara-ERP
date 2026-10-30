# optional-terminal-session-open Specification

## Purpose
TBD - created by archiving change fix-pos-terminal-optional-session-open. Update Purpose after archive.
## Requirements
### Requirement: Terminal selection is optional for all users

The POS session opening form SHALL allow all users with `pos.sessions.open` permission to open a session without selecting a terminal. Terminal selection SHALL always be optional, regardless of user role or permissions.

#### Scenario: User opens session without terminal
- **WHEN** a user with `pos.sessions.open` permission submits the session opening form without selecting a terminal
- **THEN** the session SHALL be created successfully with `terminal_id = NULL`
- **AND** the session SHALL have an `opening_float_total = 0` (or as submitted)
- **AND** no validation error SHALL be returned

#### Scenario: User opens session with terminal
- **WHEN** a user with `pos.sessions.open` permission submits the session opening form with a terminal selected
- **THEN** the session SHALL be created successfully with the selected `terminal_id`
- **AND** the session SHALL have the submitted `opening_float_total`
- **AND** the terminal SHALL be allocated to this user for the session duration

#### Scenario: Terminal field shows as optional in form
- **WHEN** a user visits GET `/pos/sessions/open`
- **THEN** the terminal field SHALL NOT display a red asterisk (*) indicating required
- **AND** the field label SHALL not include text stating terminal is mandatory
- **AND** form submission SHALL succeed whether or not a value is entered

### Requirement: Opening float is conditional on terminal selection

A strictly positive opening float SHALL be required only when a terminal is selected. The system SHALL derive it from enabled global denomination quantities by default, or accept a nominal total when the user explicitly selects nominal mode and has `pos.sessions.open.total-only`. Without a terminal, opening cash SHALL remain optional and normalize to zero.

#### Scenario: Float required when terminal selected
- **WHEN** a user selects a terminal in the session opening form
- **THEN** denomination quantity inputs and a calculated opening total SHALL be displayed by default
- **AND** submission without positive counted cash or an authorized positive nominal total SHALL fail validation

#### Scenario: Float optional when no terminal
- **WHEN** a user does not select a terminal in the session opening form
- **THEN** opening cash inputs SHALL not be required
- **AND** form submission without a float value SHALL succeed with opening cash and initial expected cash of zero

#### Scenario: Authorized nominal entry
- **WHEN** a user with `pos.sessions.open.total-only` explicitly selects nominal mode for a terminal session
- **THEN** a strictly positive manually entered opening total SHALL satisfy the opening cash requirement without counts

### Requirement: Validation treats terminal as always nullable

The `terminal_id` field in session opening validation SHALL be nullable for all users, with no permission-based conditional logic.

#### Scenario: Validation allows null terminal for any user
- **WHEN** any user with `pos.sessions.open` permission submits a session opening request with no terminal_id
- **THEN** the validation rule for `terminal_id` SHALL pass
- **AND** no permission check SHALL cause `terminal_id` to be required

#### Scenario: Validation allows valid terminal ID
- **WHEN** a user submits a session opening request with a valid, active `terminal_id`
- **THEN** the validation rule for `terminal_id` SHALL pass
- **AND** the terminal SHALL be verified to exist and be active

#### Scenario: Validation rejects invalid terminal ID
- **WHEN** a user submits a session opening request with an invalid or inactive `terminal_id`
- **THEN** the validation rule for `terminal_id` SHALL fail with appropriate error message


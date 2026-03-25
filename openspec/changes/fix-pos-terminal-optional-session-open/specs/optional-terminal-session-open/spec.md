# optional-terminal-session-open Specification

## Purpose

Define the behavior for opening POS sessions without requiring terminal selection, allowing all users to open sessions with an optional terminal field.

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

The opening float total field SHALL be required only when a terminal is selected. When no terminal is selected, the opening float SHALL be optional.

#### Scenario: Float required when terminal selected
- **WHEN** a user selects a terminal in the session opening form
- **THEN** the opening float total field SHALL become required
- **AND** form submission without a float value SHALL fail with validation error

#### Scenario: Float optional when no terminal
- **WHEN** a user does not select a terminal in the session opening form
- **THEN** the opening float total field SHALL remain optional
- **AND** form submission without a float value SHALL succeed

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

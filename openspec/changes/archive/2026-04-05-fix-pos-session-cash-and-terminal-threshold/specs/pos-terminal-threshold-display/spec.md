## ADDED Requirements

### Requirement: Display terminal cash threshold in sessions list
The system SHALL display the cash threshold value from the terminal's policy in the sessions list view.

#### Scenario: Threshold displayed for terminal session
- **WHEN** viewing sessions list and session has an associated terminal
- **THEN** a "Threshold" column displays the `terminal.policy.cash_threshold` value formatted as currency

#### Scenario: Threshold empty for non-terminal session
- **WHEN** viewing sessions list and session has no terminal (non-terminal session)
- **THEN** "Threshold" column displays "-" or blank for that row

#### Scenario: Threshold shows zero if not configured
- **WHEN** terminal policy exists but `cash_threshold` is NULL or 0
- **THEN** column displays "Rp 0" or "-" as appropriate

### Requirement: Visual alert when expected cash exceeds threshold
The system SHALL highlight the session row with warning color when the expected cash exceeds the configured terminal threshold.

#### Scenario: Row highlighted when threshold exceeded
- **WHEN** session has expected_cash_total > cash_threshold
- **THEN** the entire `<tr>` element receives CSS class `table-warning` (yellow background)

#### Scenario: No highlight when within threshold
- **WHEN** session has expected_cash_total <= cash_threshold
- **THEN** row displays with normal background (no warning class)

#### Scenario: Highlight applied at all session states
- **WHEN** session is OPEN, CLOSED, CLOSING, or FINALIZED with expected_cash > threshold
- **THEN** row receives warning highlight regardless of session state

#### Scenario: Non-terminal sessions never highlighted
- **WHEN** session has no terminal_id
- **THEN** row displays normally (threshold comparison not applicable)

### Requirement: Threshold loaded efficiently
The system SHALL load terminal policy data in a single query to avoid N+1 query overhead.

#### Scenario: Terminal policy eager-loaded
- **WHEN** PosSessionController.index() queries sessions
- **THEN** relationship `terminal.policy` is eagerly loaded in the query

#### Scenario: No additional queries in view loop
- **WHEN** rendering 15 session rows in the view
- **THEN** terminal and policy data are already loaded (0 additional queries)

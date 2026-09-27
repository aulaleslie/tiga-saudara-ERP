## MODIFIED Requirements

### Requirement: Kas column displays consistent expected cash
The system SHALL display `expected_cash_total` in the "Kas" column for all session states (OPEN, CLOSED, CLOSING, FINALIZED) to provide consistent operational visibility.

#### Scenario: Open session shows expected cash
- **WHEN** viewing sessions list and session status is OPEN
- **THEN** "Kas" column displays `expected_cash_total` formatted as currency

#### Scenario: Closed session shows expected cash
- **WHEN** viewing sessions list and session status is CLOSED
- **THEN** "Kas" column displays `expected_cash_total` (not counted_cash_total or NULL)

#### Scenario: Closing session shows expected cash
- **WHEN** viewing sessions list and session status is CLOSING
- **THEN** "Kas" column displays `expected_cash_total`

#### Scenario: Finalized session shows expected cash
- **WHEN** viewing sessions list and session status is FINALIZED
- **THEN** "Kas" column displays `expected_cash_total` (same as during OPEN)

### Requirement: Non-terminal sessions cannot finalize
The system SHALL disable the finalize button for sessions without an associated terminal and display a clear tooltip.

#### Scenario: Finalize disabled for non-terminal
- **WHEN** viewing sessions list for a session where terminal_id is NULL
- **THEN** the "Finalisasi" button is disabled with tooltip text "Finalisasi tidak diperlukan untuk sesi tanpa terminal"

#### Scenario: Finalize enabled for terminal session
- **WHEN** viewing sessions list for a CLOSED session with an associated terminal
- **THEN** the "Finalisasi" button is enabled and clickable

#### Scenario: Finalize disabled for non-terminal in OPEN state
- **WHEN** viewing sessions list for an OPEN session without terminal
- **THEN** the "Finalisasi" button remains disabled (no finalize option for non-terminal at any state)

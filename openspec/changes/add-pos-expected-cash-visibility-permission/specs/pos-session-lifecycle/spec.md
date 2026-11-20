## MODIFIED Requirements

### Requirement: Kas column displays consistent expected cash

For viewers with `pos.sessions.viewExpectedCash`, the system SHALL display `expected_cash_total` in the "Kas" column for all session states (OPEN, CLOSED, CLOSING, FINALIZED) to provide consistent operational visibility.

#### Scenario: Open session shows expected cash
- **WHEN** a viewer with balance visibility views the sessions list and session status is OPEN
- **THEN** "Kas" column displays `expected_cash_total` formatted as currency

#### Scenario: Closed session shows expected cash
- **WHEN** a viewer with balance visibility views the sessions list and session status is CLOSED
- **THEN** "Kas" column displays `expected_cash_total` (not counted_cash_total or NULL)

#### Scenario: Closing session shows expected cash
- **WHEN** a viewer with balance visibility views the sessions list and session status is CLOSING
- **THEN** "Kas" column displays `expected_cash_total`

#### Scenario: Finalized session shows expected cash
- **WHEN** a viewer with balance visibility views the sessions list and session status is FINALIZED
- **THEN** "Kas" column displays `expected_cash_total` (same as during OPEN)

#### Scenario: Session viewer lacks balance visibility
- **WHEN** an otherwise authorized user without balance visibility views sessions in any state
- **THEN** the Kas column SHALL NOT disclose expected cash in visible or hidden content


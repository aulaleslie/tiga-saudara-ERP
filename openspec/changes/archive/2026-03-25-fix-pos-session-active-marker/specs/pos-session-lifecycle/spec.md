## MODIFIED Requirements

### Requirement: Extended session status with FINALIZED state

The POS session lifecycle SHALL support a fourth status, FINALIZED, representing completed settlement. Sessions progress through: OPEN → CLOSED → FINALIZED. Separate paths exist for normal cashier closes (OPEN → CLOSING → CLOSED) and admin force-closes (OPEN → CLOSED), both converging at CLOSED before proceeding to FINALIZED.

#### Scenario: Session active marker reflects lifecycle

- **WHEN** a session is opened (OPEN status)
- **THEN** `active_marker` SHALL be set to 1
- **AND** when session transitions to CLOSED, `active_marker` SHALL be set to NULL (terminal is released for other cashiers)
- **AND** when session transitions to FINALIZED, `active_marker` SHALL be set to NULL

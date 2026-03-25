# pos-session-lifecycle Specification

## Purpose
TBD - created by archiving change pos-two-stage-settlement. Update Purpose after archive.
## Requirements
### Requirement: Extended session status with FINALIZED state

The POS session lifecycle SHALL support a fourth status, FINALIZED, representing completed settlement. Sessions progress through: OPEN → CLOSED → FINALIZED. Separate paths exist for normal cashier closes (OPEN → CLOSING → CLOSED) and admin force-closes (OPEN → CLOSED), both converging at CLOSED before proceeding to FINALIZED.

#### Scenario: Session status constants include FINALIZED

- **WHEN** PosSession class is initialized
- **THEN** the following status constants SHALL be defined:
  - `PosSession::STATUS_OPEN = 'OPEN'`
  - `PosSession::STATUS_CLOSING = 'CLOSING'`
  - `PosSession::STATUS_CLOSED = 'CLOSED'`
  - `PosSession::STATUS_FINALIZED = 'FINALIZED'`
- **AND** `activeMarkerForStatus()` method SHALL return active marker (1) for OPEN and CLOSING only
- **AND** CLOSED and FINALIZED sessions SHALL have active_marker = NULL

#### Scenario: Cashier closes session via normal flow

- **WHEN** a cashier initiates session close via POST `/pos/sell/checkout/finalize` or equivalent
- **THEN** the session status SHALL transition: OPEN → CLOSING → CLOSED
- **AND** the `closed_by` field SHALL record the cashier's user ID
- **AND** the metadata SHALL contain `closed_by_role: 'cashier'`
- **AND** variance approval (if needed) happens at this stage
- **AND** variance approval REQUIRED: session remains in CLOSING if variance exceeds threshold and approval is pending

#### Scenario: Admin force-closes session via admin override

- **WHEN** admin initiates force-close via POST `/pos/sessions/{session}/close-admin`
- **THEN** the session status SHALL transition directly: OPEN → CLOSED (skipping CLOSING state)
- **AND** the `closed_by` field SHALL record the admin user's ID
- **AND** the metadata SHALL contain `closed_by_role: 'admin'`
- **AND** no variance calculation or approval happens at close time
- **AND** the session proceeds immediately to CLOSED status

#### Scenario: Supervisor finalizes CLOSED session

- **WHEN** supervisor initiates finalization via POST `/pos/sessions/{session}/finalize`
- **AND** the session is in CLOSED status
- **AND** variance (if any) is approved or within threshold
- **THEN** the session status SHALL transition: CLOSED → FINALIZED
- **AND** a `finalized_at` timestamp SHALL be recorded
- **AND** the metadata may be updated with finalization details
- **AND** variance approval happens at this stage

#### Scenario: FINALIZED session is immutable

- **WHEN** a session is in FINALIZED status
- **THEN** neither cashier, supervisor, nor admin SHALL be able to further modify the session
- **AND** the session's cash count, variance, and settlement details are locked
- **AND** subsequent modification requests SHALL return HTTP 422 with appropriate error message

#### Scenario: Session active marker reflects lifecycle

- **WHEN** a session is opened (OPEN status)
- **THEN** `active_marker` SHALL be set to 1
- **AND** when session transitions to CLOSED, `active_marker` SHALL be set to NULL (terminal is released for other cashiers)
- **AND** when session transitions to FINALIZED, `active_marker` SHALL be set to NULL


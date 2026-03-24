# pos-supervisor-cash-finalization Specification (Delta)

## MODIFIED Requirements

### Requirement: Supervisor cash finalization with variance calculation

Supervisors (with `pos.supervisor.approval` permission) SHALL receive cash from cashiers and finalize POS sessions by entering the actual amount of cash received. The system SHALL calculate expected cash from session data, compute variance (actual - expected), and gate finalization on variance approval if variance exceeds the terminal's threshold. Finalization transitions the session from CLOSED to FINALIZED status. **Super Admin users SHALL be able to perform this finalization even if not assigned to the business setting.**

#### Scenario: Super Admin finalizes session without setting assignment
- **WHEN** a user with `Super Admin` role attempts to finalize a CLOSED session in a setting they are NOT assigned to
- **THEN** the system MUST allow the action to proceed
- **AND** the system MUST NOT throw a "Supervisor user is not assigned to current setting" error
- **AND** the session status SHALL change from CLOSED to FINALIZED if all other conditions are met

#### Scenario: Finalize with variance within threshold (no approval needed)
- **WHEN** supervisor enters actual cash amount and variance is within terminal policy's close_variance_approval_threshold
- **AND** supervisor clicks "Finalize"
- **THEN** the session status SHALL change from CLOSED to FINALIZED
- **AND** the session's `closed_by` field SHALL remain as-is (the original closer, cashier or admin)
- **AND** the session's `closed_at` field SHALL remain as-is
- **AND** a new PosSessionCashEvent SHALL be created with event_type EVENT_FINALIZE_COUNT, direction DIRECTION_NEUTRAL
- **AND** the event's amount SHALL be the actual_cash_received
- **AND** the event's metadata SHALL contain `expected_cash_total`, `variance_total`, `variance_threshold`
- **AND** the response SHALL include `status: 'FINALIZED'`, `finalized_at` timestamp, `variance_total`, and `approval_result: 'BYPASSED'`
- **AND** the UI SHALL show success message "Session finalized successfully"

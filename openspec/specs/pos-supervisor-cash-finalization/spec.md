# pos-supervisor-cash-finalization Specification

## Purpose
TBD - created by archiving change pos-two-stage-settlement. Update Purpose after archive.
## Requirements
### Requirement: Supervisor cash finalization with variance calculation

Supervisors (with `pos.supervisor.approval` permission) SHALL receive cash from cashiers and finalize POS sessions by entering the actual amount of cash received. The system SHALL calculate expected cash from session data, compute variance (actual - expected), and gate finalization on variance approval if variance exceeds the terminal's threshold. Finalization transitions the session from CLOSED to FINALIZED status. **Super Admin users SHALL be able to perform this finalization even if not assigned to the business setting.**

#### Scenario: Finalize action visibility and guidance
- **WHEN** an authenticated user with `pos.supervisor.approval` permission views the `/pos/sessions` index
- **THEN** the user SHALL see a "Finalize" action button for CLOSED sessions
- **AND** the user SHALL see a DISABLED "Finalize" action button for OPEN sessions
- **AND** the disabled button SHALL have a tooltip: "Tutup terminal terlebih dahulu sebelum finalisasi" (or similar)
- **AND** clicking "Finalize" for a CLOSED session SHALL open a modal displaying full session reconciliation details

#### Scenario: Super Admin finalizes session without setting assignment
- **WHEN** a user with `Super Admin` role attempts to finalize a CLOSED session in a setting they are NOT assigned to
- **THEN** the system MUST allow the action to proceed
- **AND** the system MUST NOT throw a "Supervisor user is not assigned to current setting" error
- **AND** the session status SHALL change from CLOSED to FINALIZED if all other conditions are met

#### Scenario: Finalization modal shows full reconciliation details

- **WHEN** supervisor clicks "Finalize" button for a CLOSED session
- **THEN** the modal SHALL display:
  - Session header: terminal code, terminal name, cashier name, opened_at, session duration
  - Sales summary: total sales amount, cash sales amount, non-cash sales amount
  - Expected cash breakdown: opening_float_total + cash_sales - safe_drops = expected_cash_total
  - Safe drop summary (if any safe drops exist): total amount safe-dropped OUT
  - INPUT FIELD: "Actual Cash Received" (currency input, required)
  - Variance display: updates in real-time as input changes, showing (actual - expected) with red color if exceeds threshold
  - Submit / Cancel buttons

#### Scenario: Expected cash calculation

- **WHEN** supervisor views finalization modal
- **THEN** expected_cash_total SHALL be calculated as: opening_float_total + sum(checkouts.grand_total WHERE payment_method.is_cash = true) - sum(safe_drops with direction OUT)
- **AND** this calculation SHALL use the same logic as `PosSessionExpectedCashCalculator`
- **AND** the modal SHALL display the formula and component amounts (opening float, cash sales, safe drops) so supervisor understands the calculation

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

#### Scenario: Finalize with variance exceeding threshold - interactive approval
- **WHEN** supervisor enters actual cash amount and |variance| > terminal policy's close_variance_approval_threshold
- **AND** supervisor clicks "Finalize"
- **AND** the supervisor DOES NOT have `pos.sessions.approve-variance` permission
- **THEN** the modal SHALL transition to an "Approval Required" state
- **AND** the modal SHALL present a supervisor authentication form (Email/Password or Identifier/PIN)
- **AND** if valid authorized supervisor credentials are provided, the session SHALL transition to FINALIZED
- **AND** the event's metadata SHALL record the ID of the supervisor who provided the override

#### Scenario: User without supervisor permission cannot finalize

- **WHEN** an authenticated user WITHOUT `pos.supervisor.approval` permission attempts POST `/pos/sessions/{session}/finalize`
- **THEN** the system SHALL return HTTP 403 Forbidden
- **AND** the session status SHALL remain CLOSED

#### Scenario: Finalize only available for CLOSED sessions

- **WHEN** supervisor views sessions index
- **THEN** the "Finalize" button SHALL only appear for sessions with status CLOSED
- **AND** the button SHALL be disabled/hidden for OPEN or FINALIZED sessions

#### Scenario: Finalize session locked for concurrent access

- **WHEN** supervisor initiates finalization on a session
- **THEN** the session record SHALL be locked with SELECT FOR UPDATE during the finalize operation
- **AND** concurrent requests to modify the same session SHALL wait or fail gracefully

#### Scenario: Finalized session cannot be modified

- **WHEN** a session is in FINALIZED status
- **THEN** neither cashier nor supervisor nor admin SHALL be able to further modify the session
- **AND** subsequent finalize requests SHALL return HTTP 422 with message "Session is already finalized"


## MODIFIED Requirements

### Requirement: Supervisor cash finalization with variance calculation
Supervisors (with `pos.supervisor.approval` permission) SHALL receive cash from cashiers and finalize POS sessions by entering the actual amount of cash received. The system SHALL calculate expected cash from session data, compute variance (actual - expected), and gate finalization on variance approval if variance exceeds the terminal's threshold. Finalization transitions the session from CLOSED to FINALIZED status. The UI SHALL provide guidance on finalize availability and support in-modal variance overrides.

#### Scenario: Finalize action visibility and guidance
- **WHEN** an authenticated user with `pos.supervisor.approval` permission views the `/pos/sessions` index
- **THEN** the user SHALL see a "Finalize" action button for CLOSED sessions
- **AND** the user SHALL see a DISABLED "Finalize" action button for OPEN sessions
- **AND** the disabled button SHALL have a tooltip: "Tutup terminal terlebih dahulu sebelum finalisasi" (or similar)
- **AND** clicking "Finalize" for a CLOSED session SHALL open a modal displaying full session reconciliation details

#### Scenario: Finalize with variance exceeding threshold - interactive approval
- **WHEN** supervisor enters actual cash amount and |variance| > terminal policy's close_variance_approval_threshold
- **AND** supervisor clicks "Finalize"
- **AND** the supervisor DOES NOT have `pos.sessions.approve-variance` permission
- **THEN** the modal SHALL transition to an "Approval Required" state
- **AND** the modal SHALL present a supervisor authentication form (Email/Password or Identifier/PIN)
- **AND** if valid authorized supervisor credentials are provided, the session SHALL transition to FINALIZED
- **AND** the event's metadata SHALL record the ID of the supervisor who provided the override

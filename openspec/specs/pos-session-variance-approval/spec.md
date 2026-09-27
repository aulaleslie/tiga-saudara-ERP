# pos-session-variance-approval Specification

## Purpose
TBD - created by archiving change pos-two-stage-settlement. Update Purpose after archive.
## Requirements
### Requirement: Permission-based variance approval for cash finalization

A new permission `pos.sessions.approve-variance` SHALL control who can approve cash variances during session finalization. When a supervisor finalizes a session and the variance exceeds the terminal's threshold, the system SHALL check for this permission. Users with this permission can approve variances; those without it cannot, and the variance approval must be escalated to an authorized approver.

#### Scenario: User with approve-variance permission can finalize with high variance

- **WHEN** a supervisor with `pos.sessions.approve-variance` permission initiates finalization
- **AND** the calculated variance exceeds the terminal's close_variance_approval_threshold
- **THEN** the supervisor can finalize the session immediately without escalation
- **AND** the response SHALL include `approval_result: 'SELF_APPROVED'` or similar indicator
- **AND** the event's metadata SHALL record the approver user ID

#### Scenario: User without approve-variance permission cannot finalize with high variance

- **WHEN** a supervisor WITH `pos.supervisor.approval` but WITHOUT `pos.sessions.approve-variance` permission initiates finalization
- **AND** the calculated variance exceeds the terminal's close_variance_approval_threshold
- **THEN** the finalization SHALL be blocked with HTTP 422 response
- **AND** the response message SHALL be "Variance approval required - please contact an authorized approver"
- **AND** the session status SHALL remain CLOSED
- **AND** a variance approval record SHALL be created in the approval queue for an authorized user

#### Scenario: Variance approval permission is distinct from general supervisor approval

- **WHEN** a user has `pos.supervisor.approval` but NOT `pos.sessions.approve-variance`
- **THEN** the user CAN finalize sessions with variance within threshold
- **AND** the user CANNOT finalize sessions with variance exceeding threshold
- **AND** the user's ability to approve other supervisor actions (cart overrides, safe drops, etc.) SHALL NOT be affected

#### Scenario: Variance approval is required at finalization, not at close

- **WHEN** a cashier closes a session with variance exceeding threshold
- **THEN** the cashier's close request SHALL be allowed (returns "requires_supervisor_approval" for existing flow)
- **AND** the variance approval SHALL NOT be required until a supervisor initiates finalization
- **AND** the timing of variance approval is during finalization (CLOSED → FINALIZED), not during close (OPEN → CLOSED)

#### Scenario: Permission is available in role management UI

- **WHEN** an admin configures user roles and permissions
- **THEN** the `pos.sessions.approve-variance` permission SHALL be visible in the POS permission group
- **AND** it SHALL have a descriptive label such as "Approve Cash Variance Settlement"
- **AND** the description SHALL clarify: "Allows approval of cash variances during session finalization"

### Requirement: Indonesian Supervisor Messages
Messages related to supervisor approval and variance override must be in Bahasa Indonesia.

#### Scenario: Invalid Supervisor Credentials
- **WHEN** Providing invalid supervisor credentials during variance override
- **THEN** The system returns 'Pengenal supervisor atau kata sandi tidak valid.' instead of 'Invalid supervisor identifier or password.'

#### Scenario: Missing Permission
- **WHEN** Supervisor lacks 'pos.sessions.approve-variance' permission
- **THEN** The system returns 'Supervisor yang disediakan tidak memiliki izin untuk menyetujui varian (pos.sessions.approve-variance).'


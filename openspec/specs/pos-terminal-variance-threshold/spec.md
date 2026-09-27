# pos-terminal-variance-threshold Specification

## Purpose
TBD - created by archiving change preserve-pos-variance-threshold. Update Purpose after archive.
## Requirements
### Requirement: Terminal policy schema retains variance threshold
The system SHALL keep `close_variance_approval_threshold` on `pos_terminal_policies` for both fresh installations and upgraded databases.

#### Scenario: Fresh installation creates threshold column
- **WHEN** the application runs migrations on an empty database
- **THEN** the `pos_terminal_policies` table is created successfully
- **AND** the table includes a `close_variance_approval_threshold` column
- **AND** no earlier migration attempts to drop the column before the table exists

#### Scenario: Upgrade repairs missing threshold column
- **WHEN** an existing installation runs migrations and `pos_terminal_policies` exists without `close_variance_approval_threshold`
- **THEN** the migration path recreates the column without dropping the table
- **AND** existing terminal policy rows remain intact
- **AND** the recreated column uses a safe default that preserves finalization behavior

### Requirement: Terminal policy configuration exposes variance threshold
The system SHALL allow authorized POS administrators to view and persist `close_variance_approval_threshold` as part of terminal policy configuration.

#### Scenario: Create or update terminal policy
- **WHEN** an authorized admin creates or edits a POS terminal policy
- **THEN** the configuration surface includes `close_variance_approval_threshold`
- **AND** the saved policy persists the submitted threshold value
- **AND** subsequent policy reads return the persisted threshold

### Requirement: Session finalization uses the terminal variance threshold
The system SHALL evaluate cash finalization variance against the terminal policy's `close_variance_approval_threshold`.

#### Scenario: Variance within threshold
- **WHEN** a supervisor finalizes a closed POS session
- **AND** the calculated variance is less than or equal to the terminal's `close_variance_approval_threshold`
- **THEN** the session may finalize without additional variance escalation
- **AND** the response metadata includes the threshold used for the calculation

#### Scenario: Variance exceeds threshold
- **WHEN** a supervisor finalizes a closed POS session
- **AND** the calculated variance is greater than the terminal's `close_variance_approval_threshold`
- **THEN** the system applies the high-variance approval rules for finalization
- **AND** the decision is based on the terminal-specific threshold rather than a removed or deprecated field


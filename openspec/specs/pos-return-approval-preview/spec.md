## MODIFIED Requirements

### Requirement: Approval Preview Separates Warnings From Blockers
The system SHALL distinguish approval blockers from warnings and informational notes. Warnings or informational notes MUST NOT change lifecycle state and MUST NOT prevent read-only preview rendering when all required execution identities are resolved. Final approval execution from the preview page MUST require zero blockers and zero warnings; informational notes alone MUST NOT block final approval.

#### Scenario: No linked Sales Returns is informational when targets are derived
- **WHEN** a pending POS Return has no linked Sales Returns
- **AND** the preview can derive complete planned Sales Return headers and details
- **THEN** the preview remains available
- **AND** reports the absence of existing linked Sales Returns as an informational note instead of a blocker or warning

#### Scenario: Warning disables final approval
- **WHEN** an authorized user opens approval preview for a pending POS Return and the latest plan contains one or more warnings
- **THEN** the preview renders the warning details
- **AND** the final approval execution control is unavailable
- **AND** opening the preview does not mutate lifecycle, stock, serial, dispatch, payment, source Sale, or Sales Return records

### Requirement: Approval Preview Is Preview Only
The system SHALL keep approval preview read-only until the user explicitly confirms final approval execution. The approval preview page SHALL provide final approval execution only for authorized pending POS Returns whose latest preview has zero blockers and zero warnings. Opening or refreshing the approval preview MUST NOT mutate data; only the explicit final approval confirmation may mutate POS Return lifecycle fields, linked Sales Return records, stock, serials, dispatches, payments, source Sales, or audit approval fields.

#### Scenario: Preview page exposes final approval only when executable
- **WHEN** an authorized user opens approval preview for a pending POS Return whose latest preview has zero blockers and zero warnings
- **THEN** the page provides a final approval confirmation control
- **AND** the POS Return remains pending until that control is submitted

#### Scenario: Preview page has no final approval when blocked
- **WHEN** an authorized user opens approval preview for a pending POS Return whose latest preview has blockers or warnings
- **THEN** the page does not provide an enabled final approval submission action
- **AND** the user can navigate back to the POS Return detail page
- **AND** no lifecycle-changing action occurs from opening the page

#### Scenario: Opening preview remains non-mutating
- **WHEN** an authorized user opens or refreshes approval preview for a pending POS Return
- **THEN** the system does not mutate POS Return lifecycle fields, linked Sales Return records, stock, serials, dispatches, payments, source sales, or audit approval fields

## ADDED Requirements

### Requirement: Approval Preview Submits Explicit Final Execution
The approval preview page SHALL submit final approval execution through an explicit confirmation action protected by `pos.returns.approve`. The final execution request MUST rebuild and validate the latest preview plan server-side before any mutation. Successful execution MUST redirect to the POS Return detail page showing completed status.

#### Scenario: Final approval redirects to completed return
- **WHEN** an authorized user confirms final approval execution for an executable preview
- **THEN** the system executes the POS Return approval lifecycle
- **AND** redirects the user to the POS Return detail page
- **AND** the POS Return detail page shows completed status

#### Scenario: Final approval revalidates stale preview
- **WHEN** the source Sale, dispatch, serial, payment, or bundle state changes after the preview page was opened but before final approval is submitted
- **THEN** the final approval request rebuilds the preview plan
- **AND** blocks execution if the rebuilt plan has blockers or warnings
- **AND** no mutation occurs

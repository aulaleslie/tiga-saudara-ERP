# POS Return Approval Preview Specification

## Purpose

The POS Return approval preview workflow provides visibility into planned execution effects before final approval submission. This specification defines requirements for rendering read-only preview data that shows execution blockers, warnings, informational notes, planned return effects, split-owner Sales Returns, dispatch anchors, serial lineage, and explicit execution modes (serial physical replacement vs. note-only replacement) without mutating data until explicit final approval confirmation.
## Requirements
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

### Requirement: Approval Preview SHALL Disclose Replacement Execution Mode
The approval preview SHALL distinguish serial inventory replacement from non-serial note-only replacement before final confirmation and SHALL show enough persisted lineage for the approver to verify the affected bundle parent or component.

#### Scenario: Serial component replacement preview
- **WHEN** a pending return replaces a serial-tracked bundle component
- **THEN** preview SHALL show the bundle and component identity, original Sale and dispatch lineage, returned serial, replacement serial, source owner/location, replacement owner/location, and planned serial movements
- **AND** it SHALL show zero customer refund and no original Sale commercial correction

#### Scenario: Non-serial replacement preview
- **WHEN** a pending return replaces a non-serial product
- **THEN** preview SHALL label the action as note-only
- **AND** it SHALL state that approval creates no receiving, dispatch, inventory, or HPP movement and that physical exchange or breakage is handled separately

#### Scenario: Whole-bundle refund preview
- **WHEN** a pending return cash-refunds a bundled quantity
- **THEN** preview SHALL show the complete persisted parent/component composition, original owner/location lineage, and the single customer-facing refund amount
- **AND** it SHALL not present component allocations as separate customer refunds

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

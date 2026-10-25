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
The approval preview SHALL distinguish serial inventory replacement from non-serial note-only replacement before final confirmation and SHALL show enough persisted lineage for the approver to verify the affected bundle parent or component. Cash-return preview MUST honor the saved whole-bundle or parent-only scope.

#### Scenario: Serial component replacement preview
- **WHEN** a pending return replaces a serial-tracked bundle component
- **THEN** preview SHALL show the bundle and component identity, original Sale and dispatch lineage, returned serial, replacement serial, source owner/location, replacement owner/location, and planned serial movements
- **AND** it SHALL show zero customer refund and no original Sale commercial correction

#### Scenario: Non-serial replacement preview
- **WHEN** a pending return replaces a non-serial product
- **THEN** preview SHALL label the action as note-only
- **AND** it SHALL state that approval creates no receiving, dispatch, inventory, or HPP movement and that physical exchange or breakage is handled separately

#### Scenario: Whole-bundle refund preview
- **WHEN** a pending return cash-refunds a bundled quantity with whole-bundle scope
- **THEN** preview SHALL show the complete persisted parent/component composition, original owner/location lineage, and the single customer-facing refund amount
- **AND** it SHALL not present component allocations as separate customer refunds

### Requirement: Preview discloses bundle scope and retained charges
Approval preview SHALL display each bundle parent's saved cash-return scope, selected quantities, included and retained components, original owner/location and sale lineage, commercial reduction, actual refund, and debt reduction. Parent-only preview MUST disclose retained component charges. Opening preview MUST remain read-only.

#### Scenario: Unpaid whole bundle
- **WHEN** preview evaluates the reported Rp890,000 unpaid bundle with whole-bundle scope
- **THEN** it shows camera Rp630,000 and microSD Rp260,000 commercial reductions across their owner sales
- **AND** it shows Rp890,000 debt reduction and zero actual cash refund

#### Scenario: Unpaid parent only
- **WHEN** preview evaluates the same bundle with explicit parent-only scope
- **THEN** it shows Rp630,000 debt reduction and zero actual cash refund
- **AND** it shows the retained microSD and Rp260,000 remaining billable

#### Scenario: Scope and composition are revalidated
- **WHEN** final approval receives stale scope, quantities, source mapping, or financial evidence
- **THEN** the server rebuilds the plan and blocks execution if the reviewed intent cannot be safely honored
- **AND** it does not substitute whole bundle or parent only silently

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

### Requirement: Repeated POS returns depend on remaining eligible products
The system SHALL allow any number of return processes for one POS transaction while eligible quantity or customer-held serialized units remain. Unrelated completed returns or monetary edits MUST NOT block another valid return. The system MUST validate affected source identities, quantities, serial occurrences and financial evidence at approval and MUST prevent overlapping claims from executing.

#### Scenario: Quantity return after unrelated serial replacement
- **GIVEN** a POS transaction contains a non-serial item of 5 pcs and a serialized item of 1 pc
- **AND** the serialized item has completed replacement A to B
- **WHEN** a return requests 1 pc of the non-serial item
- **THEN** approval SHALL be allowed subject to normal validation
- **AND** the remaining non-serial quantity SHALL be 4 and the customer-held serial SHALL remain B

#### Scenario: Successive partial returns exhaust available quantity
- **WHEN** successive returns consume the remaining quantity of an item
- **THEN** each request within available quantity SHALL be eligible regardless of previous return count
- **AND** a request beyond remaining quantity SHALL be blocked without execution effects
- **AND** completed reductions SHALL not be counted twice

#### Scenario: Independent pending requests coexist
- **WHEN** separate pending returns request distinct available items from the same POS transaction
- **THEN** both SHALL remain eligible independently
- **AND** completing one SHALL not invalidate the other's unaffected items

#### Scenario: Competing requests cannot consume the same entitlement
- **WHEN** competing requests overlap in quantity or the same held serial occurrence
- **THEN** overlapping claims SHALL be blocked before competing execution effects commit
- **AND** stale approval submission SHALL not exceed remaining entitlement

#### Scenario: Genuine source mismatch remains blocked
- **WHEN** the affected source identity or execution evidence is missing or inconsistent
- **THEN** approval SHALL be blocked with an actionable explanation
- **AND** no return execution effects SHALL be committed

### Requirement: Pending returns require renewed review for affected monetary changes
The system SHALL require explicit renewed review at the updated value when a monetary edit changes a pending return's financial basis, including relevant shared header allocations. Unrelated edits that do not change that basis MUST NOT block approval. Preview SHALL remain read-only, and final approval MUST validate the latest reviewed value.

#### Scenario: Requested item price changes
- **WHEN** an item's price changes while its return is pending
- **THEN** the return SHALL require renewed review showing the updated value
- **AND** stale approval confirmation SHALL not execute

#### Scenario: Updated value is explicitly reviewed
- **WHEN** an authorized user explicitly accepts the updated pending return value
- **THEN** approval SHALL become available if all existing approval checks pass
- **AND** original audit evidence SHALL remain preserved

#### Scenario: Unrelated item edit leaves approval valid
- **WHEN** another item's price changes without changing the pending return's financial basis
- **THEN** the pending return SHALL remain eligible without renewed monetary review

#### Scenario: Shared header change affects return allocation
- **WHEN** a header monetary adjustment changes a pending return's value
- **THEN** the return SHALL require renewed review of that updated value

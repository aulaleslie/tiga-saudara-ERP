# pos-return-approval-preview Specification

## Purpose
TBD - created by archiving change add-pos-return-approval-preview. Update Purpose after archive.
## Requirements
### Requirement: Approve Action Opens Approval Preview
The system SHALL route an authorized pending POS Return approve action to an approval preview page instead of approving the POS Return immediately. Preview access MUST require POS Return view access and `pos.returns.approve`. Opening the approval preview MUST NOT mutate POS Return lifecycle fields, linked Sales Return records, stock, serials, dispatches, payments, source sales, or audit approval fields.

#### Scenario: Pending return approve opens preview
- **WHEN** an authorized user clicks the approve action for a POS Return in `pending_approval` status and `pending` approval status
- **THEN** the system opens the POS Return approval preview page
- **AND** the POS Return remains in `pending_approval` status and `pending` approval status
- **AND** no Sales Return, Sales Return Detail, stock, serial, dispatch, payment, source sale, or approval audit mutation occurs

#### Scenario: Non pending return cannot open approval preview
- **WHEN** a user attempts to open approval preview for a POS Return that is not in `pending_approval` status with `pending` approval status
- **THEN** the system blocks the preview action with a clear lifecycle message
- **AND** keeps the POS Return unchanged

#### Scenario: Direct approval submission is unavailable during preview-only phase
- **WHEN** a user attempts to submit the direct POS Return approval mutation endpoint during this preview-only change
- **THEN** the system rejects the request with a clear preview-only lifecycle message
- **AND** the POS Return remains in `pending_approval` status and `pending` approval status
- **AND** no Sales Return, Sales Return Detail, stock, serial, dispatch, payment, source sale, or approval audit mutation occurs

### Requirement: Approval Preview Shows Generated Execution Target
The system SHALL generate and display a read-only POS Return execution target preview that shows how the POS Return would map into owner/sale-aligned Sales Return records and details if approval execution is implemented later. The preview MUST use persisted POS Return lines as the selected return intent and verify that intent against current source checkout sale, generated sale, sale detail, dispatch detail, serial, owner/location, tax, product, and bundle data. The preview MUST show generated split sale groups, planned Sales Return headers, planned Sales Return details, source owner and location, tax context, dispatch detail anchors, selected line resolutions, returned quantities, cash-return amounts, replacement serials, serial movement intent, stock movement intent, and bundle/component traces when available.

#### Scenario: Split sale target preview is displayed
- **WHEN** an authorized user opens approval preview for a pending POS Return whose source POS checkout produced multiple generated sales
- **THEN** the preview groups planned execution targets by generated source sale and owner context
- **AND** each target group shows the source sale reference, source setting, source location, tax bucket when available, and planned Sales Return header fields

#### Scenario: Line target preview is displayed
- **WHEN** an authorized user opens approval preview for a pending POS Return with actionable return lines
- **THEN** each actionable POS Return line shows its planned Sales Return Detail target
- **AND** the preview includes product, quantity, amount, resolution, sale detail, dispatch detail, source owner, source location, tax context, and stock behavior

#### Scenario: Serial target preview is displayed
- **WHEN** an actionable POS Return line has a returned serial
- **THEN** the preview shows the returned serial identity
- **AND** the preview resolves the dispatch anchor from the returned serial's `product_serial_numbers.dispatch_detail_id` before using any sale/product fallback
- **AND** the preview shows the dispatch detail that anchors the serial's original sale movement
- **AND** product replacement lines show the selected replacement serial identity when present

#### Scenario: Intake-only pending return derives planned targets
- **WHEN** a pending POS Return has actionable lines but no existing linked Sales Returns
- **THEN** the preview derives planned Sales Return targets from POS Return lines and current source data
- **AND** absence of linked Sales Returns alone does not block the preview when planned targets are resolvable

#### Scenario: Non serial dispatch anchor uses unique safe fallback
- **WHEN** an actionable non-serial stock-managed POS Return line lacks a persisted dispatch detail
- **THEN** the preview may infer the dispatch detail only when there is exactly one safe source match for the sale detail or sale/product context
- **AND** ambiguous or missing dispatch matches are reported as blockers

### Requirement: Approval Preview Reports Blockers
The system SHALL report preview blockers when the POS Return cannot yet be mapped to a safe approval execution target. The preview MUST revalidate source snapshot freshness and current live execution identities before reporting a ready state. Blockers MUST be shown without mutating data and MUST identify the affected line, source sale, dispatch detail, serial, owner/location, resolution, or bundle context when available.

#### Scenario: Missing linked execution target is reported
- **WHEN** approval preview cannot resolve the planned Sales Return target for one or more actionable POS Return lines
- **THEN** the preview shows a blocked state
- **AND** the preview lists the unresolved lines and missing execution identities
- **AND** no approval mutation occurs

#### Scenario: Missing dispatch detail is reported
- **WHEN** an actionable stock-managed POS Return line lacks a resolvable dispatch detail
- **THEN** the preview shows a blocker for that line
- **AND** the blocker explains that stock or serial reversal cannot be planned without a dispatch detail

#### Scenario: Source state drift is reported as blocker
- **WHEN** the persisted POS Return source snapshot or captured source identity no longer matches the current source sale, dispatch, serial, owner/location, tax, product, replacement serial, or bundle state needed for execution
- **THEN** the preview shows a blocked state
- **AND** the preview lists all detected source-state mismatches
- **AND** no approval mutation occurs

#### Scenario: Ambiguous mixed resolution is reported
- **WHEN** a pending POS Return contains both `cash_return` and `product_replacement` actionable lines and final line-level approval execution is not yet supported
- **THEN** the preview shows a blocker for mixed resolution execution
- **AND** no approval mutation occurs

#### Scenario: Header option mismatch is reported as non-blocking when line intent is clear
- **WHEN** the POS Return header `return_option` differs from the actionable line-level resolutions
- **AND** the actionable lines do not contain mixed unresolved execution behavior
- **THEN** the preview uses the line-level resolutions as authoritative intent
- **AND** reports the header mismatch as a warning or informational note
- **AND** no approval mutation occurs

### Requirement: Approval Preview Separates Warnings From Blockers
The system SHALL distinguish approval blockers from warnings and informational notes. Warnings or informational notes MUST NOT change lifecycle state and MUST NOT prevent preview rendering when all required execution identities are resolved.

#### Scenario: No linked Sales Returns is informational when targets are derived
- **WHEN** a pending POS Return has no linked Sales Returns
- **AND** the preview can derive complete planned Sales Return headers and details
- **THEN** the preview remains available
- **AND** reports the absence of existing linked Sales Returns as a warning or informational note instead of a blocker

### Requirement: Approval Preview Is Preview Only
The system SHALL NOT expose a final approval submission control from the approval preview page in this change. The approval preview page MAY provide navigation back to the POS Return detail page and MAY show disabled or informational messaging about future approval execution, but it MUST NOT submit approval.

#### Scenario: Preview page has no final approve submission
- **WHEN** an authorized user opens approval preview for a pending POS Return
- **THEN** the page does not provide an enabled final approve or confirm approval submission action
- **AND** the only available lifecycle-changing action on the preview page is no lifecycle-changing action
- **AND** the user can navigate back to the POS Return detail page


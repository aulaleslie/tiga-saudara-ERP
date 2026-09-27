## MODIFIED Requirements

### Requirement: Approval Preview Shows Generated Execution Target
The system SHALL generate and display a read-only POS Return execution target preview that shows how the POS Return would map into owner/sale-aligned Sales Return records and details if approval execution is implemented later. The preview MUST use persisted POS Return lines as the selected return intent and verify that intent against current source checkout sale, generated sale, sale detail, sale bundle item, dispatch detail, serial, owner/location, tax, product, and bundle data. The preview MUST show generated split sale groups, planned Sales Return headers, planned Sales Return details, source owner and location, tax context, dispatch detail anchors, selected line resolutions, returned quantities, cash-return amounts, replacement serials, serial movement intent, stock movement intent, and explicit bundle/component target rows when available.

#### Scenario: Split sale target preview is displayed
- **WHEN** an authorized user opens approval preview for a pending POS Return whose source POS checkout produced multiple generated sales
- **THEN** the preview groups planned execution targets by generated source sale and owner context
- **AND** each target group shows the source sale reference, source setting, source location, tax bucket when available, and planned Sales Return header fields

#### Scenario: Split-owner bundle component target preview is displayed
- **WHEN** an authorized user opens approval preview for a pending POS Return with an actionable bundled POS item whose checkout generated component allocations in one or more additional Sales documents
- **THEN** the preview shows each affected generated Sales document as its own planned target group
- **AND** component allocation rows from `sale_bundle_items` are displayed as explicit planned Sales Return detail targets under their owning source Sale
- **AND** each component target row shows the source POS item, returned serial when available, component product, component quantity, source owner, source location, tax context, selected line resolution, and planned stock behavior

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

#### Scenario: Mixed line resolutions are displayed
- **WHEN** a pending POS Return contains both `cash_return` and `product_replacement` actionable lines
- **THEN** the preview remains available when each individual line has a resolvable planned target
- **AND** the preview treats each line's resolution as authoritative for cash-return totals, replacement serial display, stock movement intent, and serial movement intent
- **AND** mixed resolutions alone do not block the preview

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

#### Scenario: Missing bundle component target is reported
- **WHEN** an actionable bundled POS Return line implies component-owned Sales targets but the preview cannot map one or more components to a unique generated Sale and bundle allocation row
- **THEN** the preview shows a blocked state
- **AND** the blocker identifies the affected POS Return line, source POS item, component product, and missing or ambiguous component target context when available
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

#### Scenario: Invalid line-level replacement target is reported
- **WHEN** a pending POS Return contains a `product_replacement` line whose replacement serial or replacement dispatch intent cannot be validated
- **THEN** the preview shows a blocker for that line
- **AND** the blocker does not block other lines merely because their resolutions differ
- **AND** no approval mutation occurs


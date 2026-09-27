## ADDED Requirements

### Requirement: Approval Preview Resolves Same-Sale Bundle Component Targets
The system SHALL treat a `sale_bundle_items` row from the same generated Sale as the parent POS Return line as a valid approval preview component target when persisted source data uniquely maps that row to the returned bundle component. Same-sale component target resolution MUST use deterministic evidence such as component product id, bundle id, component quantity, POS bundle trace index, `line_group_key`, and POS transaction line bundle metadata when available. The system MUST NOT reject a component target solely because the component row belongs to the same Sale as the parent line.

#### Scenario: Same-sale bundle component target is displayed
- **WHEN** an authorized user opens approval preview for a pending POS Return with an actionable bundled POS item
- **AND** one returned bundle component maps uniquely to a `sale_bundle_items` row whose `sale_id` equals the parent POS Return line `sale_id`
- **THEN** the preview includes that component allocation row as an explicit planned Sales Return detail target under the same source Sale
- **AND** the preview does not report `component_target_missing` for that component solely because the target belongs to the parent Sale

#### Scenario: Mixed same-sale and split-sale bundle component targets are displayed
- **WHEN** an authorized user opens approval preview for a pending POS Return whose bundled POS item has one component allocation in the parent generated Sale and another component allocation in a different generated Sale
- **THEN** the preview shows both component allocation rows under their owning source Sales
- **AND** each component row preserves the selected line resolution, source POS item, component product, quantity, owner, location, tax context, stock movement intent, and serial movement intent
- **AND** the preview remains ready when every component target is uniquely mapped

#### Scenario: Ambiguous same-sale component candidates remain blocked
- **WHEN** approval preview finds more than one plausible `sale_bundle_items` target for a returned bundle component after applying available POS lineage and quantity evidence
- **THEN** the preview shows a blocked state with `component_target_ambiguous`
- **AND** no approval mutation occurs

#### Scenario: Missing same-sale component target remains blocked
- **WHEN** approval preview cannot find a same-sale or split-sale `sale_bundle_items` target for a returned bundle component in a multi-sale checkout
- **THEN** the preview shows a blocked state with `component_target_missing`
- **AND** no approval mutation occurs

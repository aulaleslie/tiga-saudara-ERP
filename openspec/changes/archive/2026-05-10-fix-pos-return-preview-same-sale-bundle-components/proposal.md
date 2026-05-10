## Why

POS Return approval preview can incorrectly block bundled return lines when a bundle component is mapped to the same generated Sale as the parent POS item. This is happening for POS Return 1: the checkout has valid `pos_checkout_sales` and `sale_bundle_items` rows, but preview target planning discards the same-sale component candidate and reports that the component target cannot be mapped.

This matters now because the approval preview is intended to be the safety surface before POS Return approval execution. A false blocker prevents users from validating otherwise valid split-owner and same-owner bundle returns.

## What Changes

- Refine approval preview bundle component target resolution so same-sale `sale_bundle_items` rows can be valid component targets when they represent same-owner bundle allocations.
- Keep existing safety behavior for missing and ambiguous component targets.
- Prefer deterministic POS lineage evidence, such as `line_group_key`, bundle id, component product, quantity, and informational component amount, before falling back to broader candidate matching.
- Add regression coverage for a bundled POS Return where one component is owned by the parent Sale and another component is owned by a different generated Sale.
- No approval execution behavior is introduced; this remains preview-only.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-return-approval-preview`: Approval preview target resolution must treat same-sale bundle component allocations as valid when they are uniquely mapped through persisted source data, while preserving blockers for missing or ambiguous component targets.

## Impact

- Affected service: `Modules/Pos/Services/PosReturnApprovalPreviewPlannerService.php`
- Affected tests: POS Return approval preview planner/route coverage under `Modules/Pos/Tests/Feature/`
- Affected data contracts: existing `pos_checkout_sales`, `sale_bundle_items`, `sale_details`, POS Return lines, POS transaction line metadata, and source snapshot data
- No database schema, route, permission, or public API changes are expected.

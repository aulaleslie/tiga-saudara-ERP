## Why

Sales currently treats bundle component rows as strictly dependent on a parent `sale_details` row. That blocks future ownership-driven posting scenarios where bundle components may need to exist independently, and it tightly couples dispatch/return behavior to parent inheritance even when bundle-level context is available.

## What Changes

- Prepare Sales data and read-model behavior for optional `sale_bundle_items.sale_detail_id` while preserving current standard Sales write behavior.
- Define a dual-context rule in Sales flows: use parent-inherited context when a parent detail exists, otherwise use bundle-row self context.
- Add standalone-ready bundle-row identity/context fields so orphaned bundle rows remain valid and queryable.
- Update Sales-facing behavior contracts (dispatch, return eligibility, detail/document rendering) to specify fallback behavior when bundle rows do not have a parent detail.
- Keep standard Sales create/update in this phase as linked parent+bundle persistence only (no immediate UX or posting behavior change).

## Capabilities

### New Capabilities
- `sales-standalone-bundle-rows`: Sales domain supports standalone bundle component rows with explicit self context and deterministic fallback semantics.

### Modified Capabilities
- `sales-dispatch-bundle-tax-inheritance`: Dispatch tax-context resolution adds a fallback path from strict parent inheritance to bundle-row self context when parent detail is absent.
- `standard-sale-document-lines`: Sales document/detail rendering requirements expand to handle linked and standalone bundle component presentation without collapsing normal sale lines.

## Impact

- Affected models/tables: `sale_bundle_items` (optional parent relation + standalone context fields), related Sales read paths.
- Affected backend logic: Sales dispatch aggregation/validation, sale return eligibility mapping, sales detail/document projection.
- Affected specs/tests: dispatch bundle tax inheritance regressions, sales detail/invoice expectations, return eligibility behavior.
- No immediate POS implementation in this change; this is Sales-first preparation for a later POS-focused change.

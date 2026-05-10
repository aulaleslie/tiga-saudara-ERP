## 1. Regression Coverage

- [x] 1.1 Add or extend a POS Return approval preview planner test for a bundled return whose first component maps to the same generated Sale as the parent line and second component maps to another generated Sale.
- [x] 1.2 Assert the plan is not blocked when both same-sale and split-sale component targets are uniquely mapped.
- [x] 1.3 Assert the same-sale component appears as a component planned detail under the parent source Sale with the expected `sale_bundle_items` id, product, quantity, line group key, owner, location, and resolution.
- [x] 1.4 Assert existing missing and ambiguous component target cases still report `component_target_missing` and `component_target_ambiguous`.

## 2. Planner Implementation

- [x] 2.1 Refactor `PosReturnApprovalPreviewPlannerService` component candidate selection so same-sale `sale_bundle_items` rows are not discarded before deterministic lineage matching.
- [x] 2.2 Prefer POS lineage matches using bundle trace index, `line_group_key`, component product id, and informational component amount when POS transaction line metadata is available.
- [x] 2.3 Preserve quantity, bundle id, and apportioned quantity matching behavior for broader fallback candidate selection.
- [x] 2.4 Preserve blocker behavior when candidate selection produces zero or multiple plausible component targets after all available narrowing.

## 3. Verification

- [x] 3.1 Run the focused POS Return approval preview planner test filter.
- [x] 3.2 Use tinker or a focused route/request check against POS Return 1 to confirm the preview plan is ready and no longer emits `component_target_missing` for component product 2.
- [x] 3.3 Run an appropriate focused POS Return approval preview route or mutation-safety test filter to confirm preview-only behavior remains unchanged.

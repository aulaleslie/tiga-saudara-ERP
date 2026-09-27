## 1. POS Posting Contract

- [x] 1.1 Add bundle-component persistence in `InlinePosCheckoutPostingAdapter` so finalize writes `sale_bundle_items` rows for posted bundle lines.
- [x] 1.2 Implement deterministic field mapping for persisted bundle rows (`sale_id`, `sale_detail_id`, `bundle_id`, `bundle_item_id`, `product_id`, `name`, `quantity`, `price`, `sub_total`, `tax_id`, `tax_amount`, `line_group_key`).
- [x] 1.3 Ensure idempotent finalize replay does not duplicate bundle-row inserts for the same checkout.

## 2. Split Posting Compatibility

- [x] 2.1 Verify split posting path persists bundle rows for every generated sale group by reusing inline posting behavior.
- [x] 2.2 Align persisted tax/group context with split allocation snapshots so bundle row context matches dispatch and stock mutations.
- [x] 2.3 Validate parent-linked vs standalone-compatible persistence behavior for bundle rows in multi-source scenarios.

## 3. Sales Read-Path Integration

- [x] 3.1 Update affected Sales read/return paths (where needed) to consume POS-persisted bundle rows without parent-only assumptions.
- [x] 3.2 Confirm sales detail and document projection paths remain consistent when POS persists bundle rows.
- [x] 3.3 Add safeguards so missing parent linkage uses standalone fallback fields rather than hard-failing validation.

## 4. Regression Coverage

- [x] 4.1 Add POS finalize feature tests asserting `sale_bundle_items` persistence for bundle checkout in inline flow.
- [x] 4.2 Add split-posting feature tests asserting per-group bundle-row persistence in mixed-source checkout.
- [x] 4.3 Add integration tests that verify POS-persisted bundle rows are usable by Sales return eligibility and read projections.
- [x] 4.4 Run targeted POS and Sales test suites and document any residual risks before apply/merge.

## Why

Sales was prepared to accept standalone `sale_bundle_items` rows, but POS checkout posting still does not persist bundle component rows. In mixed-business and bundle-only checkout flows, this creates a contract gap where dispatch and return contexts can be incomplete because downstream Sales read paths expect bundle-row context that POS never writes.

## What Changes

- Add POS checkout posting behavior to persist bundle component rows into `sale_bundle_items` during finalize, including standalone-safe fields (`sale_detail_id` handling, `tax_id`, `tax_amount`, `line_group_key`).
- Define deterministic mapping rules for parent-linked vs standalone bundle-row persistence in inline and split posting paths.
- Ensure POS-created sales with bundle components preserve downstream consistency for Sales detail rendering, invoice projection, dispatch aggregation, and return eligibility.
- Add regression coverage for bundle-only and mixed-cart checkout flows across multi-source split posting.

## Capabilities

### New Capabilities
- `pos-standalone-bundle-row-posting`: POS checkout posting persists bundle component rows with standalone-compatible context so downstream Sales features can resolve tax/grouping without parent-only assumptions.

### Modified Capabilities
- `sales-standalone-bundle-rows`: extend requirement coverage to include POS-originated persistence guarantees and compatibility expectations for downstream read paths.

## Impact

- Affected code: `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`, `Modules/Pos/Services/Adapters/SplitPosCheckoutPostingAdapter.php`, `Modules/Pos/Services/FinalizePosCheckoutService.php`, Sales return/read paths that rely on persisted bundle context.
- Affected tests: POS finalize + split posting feature tests, Sales standalone bundle row integration assertions, return eligibility regression tests.
- Data impact: increased `sale_bundle_items` writes from POS finalize; no destructive schema changes expected.

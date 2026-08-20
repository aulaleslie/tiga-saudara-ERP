## Verification Notes

### 5.1 Persisted lineage sufficiency

Existing persisted checkout/Sale/dispatch/serial/history records were sufficient for every code path hardened by this change — no schema changes were made, matching design.md's "no database migration is planned" decision.

One documented limitation, confirmed during Section 3 (Receipt and Transaction Detail Traceability):

- **Historical POS transactions predating this change** (or any record where `PosTransaction`/persisted Sale lineage is unavailable, e.g. very old draft/legacy rows) fall back to `PosReceiptService::compositionFromLineMeta()`, which reads only the cart-line snapshot (`name`/`qty`) and never fabricates component serial data. This is intentional per design.md's "Project historical component serials from posted data" decision — no inferred backfill was added. Operators viewing such historical receipts will see bundle composition without component serials; this is a pre-existing display limitation, not a regression.
- **Two identical bundles purchased in the same Sale** (e.g. the same bundle definition appearing as two separate parent line items in one transaction) — **fixed** during a post-review hardening pass. `DispatchDetail.sale_detail_id` was declared fillable but the column never existed in the schema and was never populated; migration `2026_08_21_120000_add_sale_detail_id_to_dispatch_details_table` adds it, `InlinePosCheckoutPostingAdapter::recordStockMovement()` now populates it from the owning parent `SaleDetails` row for both parent and component dispatch chunks, and `PosReceiptService::bundleCompositionGroupsByProduct()` now keys component-serial resolution by `(sale_detail_id, product_id)` first — which uniquely identifies each bundle occurrence — falling back to the old `(product_id, bundle_id)` composite match only for historical `DispatchDetail` rows created before this column existed (where the ambiguity is unavoidable without inferred backfill, consistent with the historical-rows limitation above).
- **Component replacement dispatch lineage** (POS Return "product replacement" resolution for a bundle component) remains informational-only: no `DispatchDetail`/serial-state effect is produced for a component replacement row today. Making this executable would require capturing a distinct replacement serial per component (today `PosReturnLine.replacement_serial_id` is one field shared by the parent and all its components) — a new data-capture/UI capability outside this change's scope per design.md's decision to reuse existing return-lifecycle mechanisms rather than invent new ones.

### 5.2 Test scope

Only the focused regression files covering touched implementations were run — no full-suite task was added, per design.md's explicit non-goal ("Add a full application test-suite task"). Final consolidated run across every file touched or extended by this change: **111 tests / 754 assertions, all passing.**

Two post-review hardening passes (see below) added regression coverage and fixes for four defects two reviews identified: duplicate serial reuse within a single append call, partial bundle-component returns silently over-restoring every serial in a shared dispatch, component-serial exclusivity racing past the parent-line-only guard, and repeated identical-bundle occurrences in one Sale sharing (unioning) component serials on receipt display. All four are now covered by dedicated tests and closed in the implementation — the last one required a small migration (`dispatch_details.sale_detail_id`) rather than being a pure application-code fix, which is why it's called out separately from the others.

Files run together for the final pass:
- `POSBundleCartManagementTest`, `PosCartOverrideMetadataRefreshTest`, `PosCartWriterLockCoverageTest`
- `POSSplitSerialBundleCheckoutTest`, `SalesDispatchBundleComponentSerialRegressionTest`
- `POSSplitBundleReceiptReconstructionTest`, `POSTransactionReceiptReprintTest`, `POSReceiptGenerationTest`
- `POSReturnSerialSplitOwnerTest`, `POSReturnBundleRegressionTest`, `POSReturnBundleComponentSerialLineageTest`, `POSReturnApprovalPreviewPlannerTest`

One pre-existing failure outside this change's scope was identified and left untouched, confirmed via `git stash` to fail identically on `main`: `POSReturnCrossOwnerReplacementTest::cross_owner_replacement_...` (a `Carbon` object identity assertion, unrelated to serial traceability) and a `sales.reporting-date.override` permission-seeding gap affecting `SaleShowSerialBadgeTest` and other unrelated `Modules/Sale` tests.

### 5.3 Supported matrix

| Scenario | Cart | Checkout/Dispatch | Receipt/Detail | Returns |
|---|---|---|---|---|
| Standalone serialized SKU | ✅ unique across cart | ✅ locked + revalidated at posting | ✅ `assigned_serials` | ✅ existing parent-line path (pre-existing, unmodified) |
| Bundle-parent serialized | ✅ unique across cart | ✅ locked + revalidated at posting | ✅ `assigned_serials` on parent line | ✅ existing parent-line path (pre-existing, unmodified) |
| Bundle-component serialized | ✅ unique across cart, cart-wide (incl. vs. parent/other components) | ✅ locked + revalidated at posting, own `DispatchDetail`/history | ✅ `bundle_composition[].serials`, separate from parent | ✅ own serial resolved from own `DispatchDetail`; correctly restored on receiving |
| Same serialized SKU standalone **and** as bundle component in one cart/transaction | ✅ rejected as duplicate across positions | ✅ each position's own `DispatchDetail`/serial | ✅ disambiguated by `bundle_id` (non-null for component, null for standalone) | ✅ disambiguated the same way at return-eligibility resolution |
| Multiple serialized components in one bundle | ✅ independent uniqueness per component | ✅ independent `DispatchDetail`/history per component | ✅ each component's own serial list | ✅ each component's own serial resolved independently |
| Split-owner posting (component fulfilled by a different owner than the parent) | n/a (cart is pre-split) | ✅ exactly one dispatch + one history entry per component serial, invariant-checked partition across groups | ✅ serials merged from the correct owner's `Sale`/`DispatchDetail` only | ✅ owner-specific `Sale`/`DispatchDetail` resolved per component via `SaleBundleItem` matching |
| Stale serial (status/location/dispatch changed after cart assignment) | n/a | ✅ rejected at posting under lock, zero partial effects | n/a | n/a |
| Matching idempotency replay | n/a | ✅ returns stored result, no duplicate serial/stock/history effects | n/a | n/a |
| Fractional required component serial quantity | ✅ rejected, not rounded | n/a | n/a | n/a |
| Historical rows predating this change | n/a | n/a | ✅ falls back to cart-snapshot composition (name/qty only, no serials) — documented limitation, no inferred backfill | n/a |
| Two returns claiming the same returned serial (parent) | n/a | n/a | n/a | ✅ blocked at `submitDraftForApproval` (exclusivity guard); released after rejection/non-consuming state |
| Two returns claiming the same component serial | n/a | n/a | n/a | ✅ blocked at `synchronize()` (plan-persistence exclusivity guard, covers pending-approval-vs-pending-approval races the parent-line-only submission-time guard cannot see) |
| Appending the same serial twice to one cart position (parent or component) | ✅ rejected — own-position set checked independently of the cart-wide exclusion | n/a (blocked before checkout) | n/a | n/a |
| Partial bundle return where the component dispatch's serial count exceeds the returned quantity | n/a | n/a | n/a | ✅ blocked as `component_serial_partial_return_ambiguous` rather than restoring every serial in the dispatch |
| Component replacement (product-replacement return resolution) | n/a | n/a | n/a | ⚠️ informational-only, no dispatch/serial effect (documented limitation, see 5.1) |
| Two identical bundle occurrences in one Sale | n/a | ✅ `DispatchDetail.sale_detail_id` populated per occurrence | ✅ resolved via `sale_detail_id`, no cross-occurrence union | n/a |

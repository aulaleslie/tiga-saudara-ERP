# Tasks

Verification policy: run focused tests only (`php artisan test --filter=...` or `composer test:fresh-sqlite -- --filter=...`), never the full suite.

## 1. Reproduce with production shape

- [x] 1.1 Add `Modules/Pos/Tests/Feature/POSReturnBundleParentCarrierCashReturnTest.php`. It builds one serial-tracked parent `sale_details` row, three non-serial `sale_bundle_items` on that row, one dispatch detail per component and a POS transaction line with bundle metadata. It saves a `whole_bundle` cash return. Verify `php artisan test --filter=POSReturnBundleParentCarrierCashReturnTest` fails with the "harus mencakup seluruh unit bundel" message.

## 2. Component line identity

- [x] 2.1 Add `PosReturnLine::isComponentLine()` (design decision 2) with unit cases for sibling-carrier, parent-carrier and parent lines. Verify with `--filter=PosReturnLineComponentIdentity`.
- [x] 2.2 Replace `bundle_parent_sale_detail_id != sale_detail_id` checks in `PosReturnSubmissionService` (L945, L981-983, L1131-1135, L1272-1277) and `PosReturnApprovalPreviewPlannerService` (L384, L441, L1376) with the helper. Grep `Modules/Pos` and `app/Support/PosReturn` for remaining comparisons and convert them. Verify the grep is clean and `--filter=POSReturnApprovalPreviewBundleScopeTest` passes.
- [x] 2.3 Make `submitDraftForApproval` pass the real `__component_product_id`, component dispatch and per-bundle quantity for component lines (design decision 3). Verify a resubmit case in the 1.1 test keeps exactly 3 component lines.

## 3. Quantity guard per product

- [x] 3.1 Add a product filter to `PosReturnQuantityGuard::getRefundableQuantity` and `getReturnableQuantity`, and pass the effective product from all callers in Submission and the Planner. Add cases to `POSReturnQuantityGuardTest` for parent and components sharing a row. Verify `--filter=POSReturnQuantityGuardTest` passes.

## 4. Synthesis from the resolver

- [x] 4.1 In `synthesizeBundleCashReturnComponents`, build cash-return component lines from the resolver's components (carrier, dispatch, product), remove the sibling-row skip, and set `component_quantity_per_bundle`. Keep the replacement-mode filter unchanged. Verify the 1.1 test now saves with 1 parent + 3 component lines on dispatches 433369-style ids.
- [x] 4.2 Set component line `unit_price`, `line_total` and `expected_cash_amount` to 0 and store `allocated_price` in `line_meta` (design decision 5). Verify in the 1.1 test that the return total equals the bundle price and the component amounts are 0.
- [x] 4.3 Add a duplicate-return case: a second whole-bundle return for the same serial is rejected. Verify in the 1.1 test.

## 5. Approval and stock-in

- [x] 5.1 Trace `PosReturnLifecycleService` and `PosReturnApprovalPlanPersistenceService` for component lines whose carrier is the parent row. Extend the 1.1 test to approve the return and assert stock returns per component at the source location and serial 424 is restored. Fix any lookup keyed by `sale_detail_id` alone. Verify the test passes.

## 6. Regression check

- [x] 6.1 Run the focused bundle return set and confirm it is all green; justify any changed expectation against the spec: `php artisan test --filter='POSReturnBundle|POSReturnDraftBundleScope|POSReturnQuantityGuard|POSReturnApprovalPreviewBundleScope|BundleCarrierRow'`.
- [x] 6.2 Against the local DB copy, dry-run a whole-bundle cash return validation for TPI-TXN-2026-11-0170 inside a rolled-back transaction (tinker) and confirm there is no completeness error.

## Workflow follow-up

- After deploy, retry TPI-TXN-2026-11-0170 with "Paket Lengkap".
- Archive the change once reviewed.

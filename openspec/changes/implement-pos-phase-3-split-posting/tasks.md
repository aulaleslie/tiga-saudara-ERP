## 1. Data Model and Feature Gate

- [x] 1.1 Create migration for `pos_checkout_sales` with unique `(pos_checkout_id, split_key)` and supporting indexes
- [x] 1.2 Add split summary storage on `pos_checkouts` (column or metadata key) with backward-compatible defaults
- [x] 1.3 Add/configure feature flag `pos.checkout.split_posting.enabled` default OFF

## 2. Split Planning and Allocation Services

- [x] 2.1 Implement `PosCheckoutSplitPlannerService` to group posting units by `source_setting_id + source_location_id + tax_bucket`
- [x] 2.2 Integrate serial-source and stock-allocation-source resolution into planner input
- [x] 2.3 Implement tax fallback in planner (default tax, else latest active tax)
- [x] 2.4 Implement `PosCheckoutPaymentSplitService` with deterministic minor-unit largest-remainder allocation by `split_key`

## 3. Posting Adapter and Finalize Flow Integration

- [x] 3.1 Implement `SplitPosCheckoutPostingAdapter` to post one sales bundle per split group
- [x] 3.2 Extend `PosCheckoutPostingAdapter` contract to support grouped posting result while preserving compatibility shape
- [x] 3.3 Update `FinalizePosCheckoutService` to call split adapter when flag enabled and inline adapter when disabled
- [x] 3.4 Persist split mapping rows to `pos_checkout_sales` and enforce deterministic split ordering

## 4. Backward-Compatible Response and Persistence

- [x] 4.1 Extend finalize response with `split_groups`, `sales`, and `sale_payments` arrays
- [x] 4.2 Preserve top-level legacy fields `sale_id`, `sale_payment_id`, and `dispatch_ids` from first deterministic split group
- [x] 4.3 Persist legacy compatibility pointers and split summary for replay/read consistency

## 5. Idempotency and Reconciliation Guarantees

- [x] 5.1 Ensure replay with same idempotency key returns existing split map and does not create duplicate side effects
- [x] 5.2 Add reconciliation assertions so split totals exactly match checkout totals before commit
- [x] 5.3 Add/verify query path for checkout-to-sales reconciliation using `pos_checkout_sales`

## 6. Tests and Rollout Validation

- [x] 6.1 Add unit tests for split planner grouping and tax fallback behavior
- [x] 6.2 Add unit tests for payment split allocation and deterministic rounding/tie-breaks
- [x] 6.3 Add feature tests for split posting multi-group finalize and compatibility payload fields
- [x] 6.4 Add feature tests for split idempotency replay (no duplicates, stable group order)
- [x] 6.5 Re-run finalize/serial/tax regression tests and document pilot rollout checks with flag enablement

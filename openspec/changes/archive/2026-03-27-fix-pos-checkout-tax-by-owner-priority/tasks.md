## 1. Source-Owner Tax Policy Alignment

- [x] 1.1 Refactor finalize posting flow to consume one authoritative chunk tax policy snapshot (`source_is_pkp`, effective tax id/rate) without serial-only recomputation in `InlinePosCheckoutPostingAdapter`.
- [x] 1.2 Ensure serial and non-serial chunks both apply source-owner `is_pkp` gating before setting `effectiveTaxId`, `dispatch_details.tax_id`, and stock bucket decrement type.
- [x] 1.3 Verify planner/output contract remains compatible with existing `split_key` composition and split summary payload fields.

## 2. Non-Serial Taxable Allocation Priority

- [x] 2.1 Update `ResolvePosStockAllocationsService` taxable non-serial allocation to partition allowed locations by owner priority (non-PKP first, PKP second).
- [x] 2.2 Preserve configured sales-location order as deterministic tie-break within each owner-priority partition.
- [x] 2.3 Keep non-tax and serial assignment paths unchanged except where required for shared policy consistency.

## 3. Split Planning and Posting Consistency

- [x] 3.1 Confirm `PosCheckoutSplitPlannerService` tax bucket resolution and `InlinePosCheckoutPostingAdapter` persisted tax results are consistent for mixed-owner carts.
- [x] 3.2 Ensure mixed-owner scenarios persist correct `sale_details.product_tax_amount` and `dispatch_details.tax_id` per chunk owner policy.
- [x] 3.3 Validate reconcile invariants remain intact (`subtotal`, `tax_total`, `grand_total`, `paid_total`) after policy alignment.

## 4. Regression Test Coverage

- [x] 4.1 Add/adjust feature tests for mixed-owner checkout where serial chunk has tax metadata but source owner is non-PKP, asserting persisted non-tax outcome.
- [x] 4.2 Add/adjust feature tests for non-serial taxable allocation order, asserting non-PKP source stock is consumed before PKP source stock with stable location ordering.
- [x] 4.3 Run targeted POS test suites for stock resolver, split planner, finalize posting, and idempotency to confirm no behavioral regression.

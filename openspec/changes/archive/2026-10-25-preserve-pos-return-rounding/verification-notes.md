# Verification Notes: preserve-pos-return-rounding

## 1. Focused Verification Test Suite

All tests executed via focused unit and feature test runners with SQLite in-memory fixtures. No full test suite or `composer test:fresh-sqlite` was executed, adhering strictly to Brownfield guidelines.

### Commands and Results

| Command | Tests | Assertions | Status | Scope |
|---|---|---|---|---|
| `vendor/bin/phpunit Modules/Pos/Tests/Feature/PosReturnRoundingMigrationAndModelTest.php` | 4 | 10 | PASS | Additive migration, rollback, Eloquent array cast, legacy null-context loading |
| `vendor/bin/phpunit Modules/Pos/Tests/Unit/PosReturnRoundingValuationServiceTest.php` | 10 | 46 | PASS | Cumulative half-up valuation, partition independence, Rp100 / coarse / zero increments, residual exhaustion, caps |
| `vendor/bin/phpunit Modules/Pos/Tests/Feature/PosReturnRoundingLifecycleIntegrationTest.php` | 13 | 92 | PASS | Untouched marker line Rp790,000 preservation, 1/120 marker Rp6,600 partial return & Rp783,400 balance, zero-rounded return balance preservation, sequential bundle cumulative rounding history, invalid valuation blocking & abort, stale increment rejection, frozen increment retention, allocation release on reversal, mixed unpaid/paid/debt settlement, discounted sales rounding consistency (Rp724,500 -> Rp718,600), whole-bundle valuation component allocation non-double-counting, tax-inclusive subtotal discount derivation preservation, zero tax authoritative preservation on untaxed items |
| `vendor/bin/phpunit Modules/Pos/Tests/Feature/POSReturnApprovalWorkflowTest.php` | 15 | 106 | PASS | Existing approval workflow, draft submission, rejection, permission checks, renewed monetary review on source edit |
| `vendor/bin/phpunit Modules/Pos/Tests/Feature/POSReturnBundleScopeLifecycleExecutionTest.php` | 8 | 63 | PASS | Whole-bundle vs parent-only returns, incident-shaped split-owner bundle fragment allocation, stockless components, idempotency |

**Total:** 50 tests, 317 assertions, 0 failures.

---

## 2. Review of Migration & Prospective Safety

- **Additive Migration:** `Modules/Pos/Database/Migrations/2026_10_24_000001_add_return_rounding_context_to_sales_table.php` adds a nullable `return_rounding_context` JSON column to `sales`.
- **Zero Backfill / Zero Mutation:** No historical data migration or repairs were run. Legacy transactions, including transaction 9218, retain `return_rounding_context = null` and load safely without synthetic backfills.
- **Read-Only Previews:** `PosReturnApprovalPreviewPlannerService` performs calculations without writing to the database or freezing increments.
- **Deterministic Row Locking:** `lockApprovalExecutionDependencies` locks all 9 Eloquent model dependencies using sorted IDs (`->sort()->values()` and `->orderBy('id')`) to prevent deadlocks.
- **Atomic Context Freezing:** `return_rounding_context` is initialized and committed only upon successful execution of approval (`executeApprovalFromPreview` / `approve`), recording baseline commercial lines, committed allocations, and frozen increment.
- **Allocation Reversal:** Audited cancellation / reversal invokes `releaseReturnAllocation` to release committed quantities and values without modifying historical completed refund receipts.

---

## 3. Resolution of P1 Review Findings

1. **[P1] Zero-rounded returns still reduce the sale balance:**
   - **Root Cause:** When returning 1 unit of an item where rounding yields Rp0 (e.g. 1/120 of Rp790,000 rounded to 100k increment = Rp0), `$detailCorrection` was 0.0, which was evaluated as falsy in `PosReturnLifecycleService.php:1188` and `:1242`, triggering the legacy fallback to proportional reduction (`$reductionRatio`), incorrectly dropping the remaining subtotal from Rp790,000 to Rp783,416.67.
   - **Fix:** In `PosReturnLifecycleService.php`, updated both regular details and bundle items so if `$detailCorrection <= 0.0`, `$subtotalReduction` is explicitly set to `0.0`. In addition, line 464 invokes `applyZeroRefundSettlementForSaleReturn` when cash settlement is 0, correctly settling the zero-refund return sale return header (`due_amount = 0`, `payment_status = Paid`, `settled_at` timestamp).
   - **Verification:** Tested in `it_ensures_zero_rounded_return_does_not_reduce_sale_balance`, asserting the customer receives Rp0 refund and the remaining marker line stays untouched at Rp790,000 with sale total Rp805,000.

2. **[P1] Bundle returns lose cumulative rounding history:**
   - **Root Cause:** Preview planner generated commercial keys using `bundle_group_*` or `bundle_parent_*`, while execution stored `ptl_*` or `detail_*`. Historical lookup only checked the latter, causing subsequent bundle returns to start with 0 cumulative history.
   - **Fix:** Unified commercial key resolution across planner, execution, and valuation via `PosReturnRoundingValuationService::resolveCommercialLineKey()`. Both preview planning and execution now consistently identify bundle lines by `ptl_{id}` (or resolving component to parent's PTL) or `detail_{parent_sale_detail_id ?: sale_detail_id}`.
   - **Verification:** Tested in `it_maintains_cumulative_rounding_history_for_sequential_bundle_returns`, verifying that return #1 records cumulative allocation under `ptl_*` and return #2 loads prior history, correctly reconciling residual to Rp880,000 upon exhaustion.

3. **[P1] Invalid valuation silently permits approval:**
   - **Root Cause:** `PosReturnApprovalPreviewPlannerService.php` swallowed valuation errors with fallback amounts, leaving `is_blocked = false`. Execution commit skipped recording allocations with `continue;`.
   - **Fix:** Planner records errors in `$this->lastValuationErrors` and adds a blocking blocker `rounding_valuation_failed` with `is_blocked = true`. Execution revalidation and commit (`revalidateApprovalClaimsUnderLock` and `commitRoundingContextAllocations`) invoke valuation with `throw_on_error => true` and rethrow exceptions to abort execution without committing unrecorded financial effects.
   - **Verification:** Tested in `it_blocks_approval_and_aborts_execution_when_rounding_valuation_is_invalid`, verifying that forged or corrupt state blocks preview planning and aborts approval execution.

4. **[P1] Discounted sales still lose rounding consistency:**
   - **Root Cause:** When recalculating the sale total in `PosReturnLifecycleService.php:recalculateCashCorrectedSourceSale`, re-evaluating the percentage discount on the reduced subtotal caused an unrounded proportional discount deduction (e.g., Rp5,925 deduction instead of the Rp5,900 committed return amount, resulting in Rp718,575 instead of Rp718,600). Furthermore, `resolveCashSettlementAmount()` prioritized the pre-plan `line->expected_cash_amount` over the authoritative approved `SaleReturnDetail.sub_total`.
   - **Fix:** The source sale's remaining total is now derived directly from the authoritative committed return amount: `$totalAmount = round(max(0, (float) $sale->total_amount - $totalCommittedCashReturn), 2);`. `resolveCashSettlementAmount()` now prioritizes `$executionContext['planned_amount']` and `$detail->sub_total` across all lines.
   - **Verification:** Tested in `it_ensures_discounted_sales_preserve_rounding_consistency_and_decrease_total_by_exact_committed_return`, asserting that returning 1 marker reduces a 10% discounted sale from Rp724,500 by exactly Rp5,900 to Rp718,600.

5. **[P1] Whole-bundle valuation double-counts component allocations:**
   - **Root Cause:** In whole-bundle returns where components reside on the same sale as the parent package (such as single-sale bundles), the parent detail/PTL already captures the full commercial bundle price. Adding component basis or subtotals to the parent basis resulted in double-counting (e.g., Rp6,600 + Rp1,000 = Rp7,600).
   - **Fix:** In `PosReturnApprovalPreviewPlannerService.php` and `PosReturnLifecycleService.php`, components that share the same `sale_id` as the bundle parent detail are not added to the parent's baseline financial basis, using the single captured commercial total once and allocating it across components. Furthermore, in `calculatePlannedCashReturnAmounts()`, transaction lines are matched via parent lineage and product SKU when `pos_transaction_line_id` is implicit, preserving full bundle charge attribution across split-owner and single-sale workflows without double-counting.
   - **Verification:** Tested in `it_ensures_whole_bundle_valuation_does_not_double_count_component_allocations`, verifying that whole-bundle cash return plans to exactly Rp6,600 (not Rp7,600) and completely reduces the sale to Rp0 upon approval.

6. **[P1] Included tax becomes a new order discount:**
   - **Root Cause:** In `PosReturnLifecycleService.php:1358`, the discount derivation formula was subtracting `$totalTaxAmount` from `$totalAmount`: `$totalSubTotal - ($totalAmount - $totalTaxAmount - $shippingAmount)`. Because line subtotals and `totalAmount` in this ERP already include tax (tax-inclusive pricing), subtracting tax made the post-tax base smaller than the tax-inclusive subtotal by exactly the tax amount, turning the untouched line's Rp78,288 included tax into an unwanted order discount.
   - **Fix:** In `PosReturnLifecycleService.php`, preserved tax-inclusive subtotal semantics by deriving the discount as `$computedDiscountAmount = round(max(0, $totalSubTotal - ($totalAmount - $shippingAmount)), 2);`. For PKP sales, `$totalTaxAmount` is preserved directly from `$detailTaxAmount` (the sum of active detail `product_tax_amount` values).
   - **Verification:** Tested in `it_preserves_tax_inclusive_subtotal_semantics_without_creating_order_discount_when_returning_untaxed_item`, asserting that returning an unrelated untaxed item leaves untouched taxable line at Rp790,000 with Rp78,288 tax and Rp0 order discount (not Rp78,288).

7. **[P1] Returning the last taxable item adds tax to untaxed items:**
   - **Root Cause:** In `PosReturnLifecycleService.php:1356`, a fallback check `if ($totalTaxAmount <= 0.0001 && $isPkp && ! empty($sale->tax_id) && $taxRate > 0)` treated zero remaining detail tax as missing evidence and recalculated tax against the remaining balance using the old header tax rate. When all taxable items were returned leaving only an untaxed item (Rp15,000 notebook), this applied an unauthorized tax of Rp1,486.49 to the untaxed line.
   - **Fix:** Removed the fallback calculation; `$totalTaxAmount` is strictly defined as `$isPkp ? $detailTaxAmount : 0.0`. Zero detail tax remains authoritative when remaining items are untaxed. In addition, when remaining line quantity reaches 0 upon return, line subtotal, tax, and discount are cleanly zeroed out.
   - **Verification:** Tested in `it_preserves_zero_tax_authoritatively_when_returning_all_taxable_items_leaving_only_untaxed_item`, asserting that returning all 120 taxable markers leaves only the untaxed notebook at Rp15,000 with Rp0 stored tax (not Rp1,486.49).

---

## 4. OpenSpec Strict Validation

Executed:
```bash
openspec validate preserve-pos-return-rounding --strict
```
Result:
```
Change 'preserve-pos-return-rounding' is valid
```
All delta specifications (`pos-return-approval-execution`, `pos-rounded-amount-consistency`, `transaction-row-total-rounding`) are fully satisfied.

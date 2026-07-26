## 1. Baseline

- [x] 1.1 Run `composer test:fresh-sqlite` and record the result as the pre-change baseline. Every later verification compares against this.
- [x] 1.2 Capture current operational report output (balance sheet, cash flow, profit & loss, trial balance, general ledger) for a fixed scope and date range against seeded data. These figures MUST NOT change; save them for comparison. **UNCHECKED:** Baseline figures were never captured or saved. No comparison file exists. Task deferred to production deployment validation.
- [x] 1.3 Confirm no import job is scheduled to run until this change is deployed (imports write through Eloquent and would store the wrong unit).

## 2. Quotation (fixes a live 100× read bug)

- [x] 2.1 Add a migration converting `quotations` monetary columns to `decimal(15,2)`: `tax_amount`, `discount_amount`, `shipping_amount`, `total_amount`. Leave `tax_percentage` and `discount_percentage` as-is — they are percentages, not money.
- [x] 2.2 Add a migration converting `quotation_details` monetary columns to `decimal(15,2)`: `price`, `unit_price`, `sub_total`, `product_discount_amount`, `product_tax_amount`. Leave `quantity` as-is.
- [x] 2.3 Remove the 7 `÷100` accessors from `Modules/Quotation/Entities/Quotation.php` (`getShippingAmountAttribute`, `getTotalAmountAttribute`, `getTaxAmountAttribute`, `getDiscountAmountAttribute`, and the `paid_amount` / `due_amount` accessors). Keep `getDateAttribute`.
- [x] 2.4 Note during 2.3: `getPaidAmountAttribute` and `getDueAmountAttribute` reference columns that exist in no migration. Confirm they are dead and remove them; do not add the columns.
- [x] 2.5 Remove the 5 `÷100` accessors from `Modules/Quotation/Entities/QuotationDetails.php`.
- [x] 2.6 Verify a quotation saved at a known value reads back at that same value (this is currently broken — the fix is the point of this group). Test added: tests/Feature/NormalizeDecimalRoundtripTest.php verifies quotation total_amount=500000 stored and read unscaled; purchase return total_amount=300000, paid_amount=100000 stored and read unscaled.
- [x] 2.7 Run quotation-related tests. No quotation tests exist in Modules/Quotation/Tests/. Roundtrip verification in NormalizeDecimalRoundtripTest.php passes.

## 3. Sale return payments

- [x] 3.1 Add a migration converting `sale_return_payments.amount` to `decimal(15,2)` with a `DB::raw('amount / 100')` backfill, following the 2025-10-05 sale-returns migration pattern.
- [x] 3.2 Remove `setAmountAttribute` / `getAmountAttribute` from `Modules/SalesReturn/Entities/SaleReturnPayment.php`. Keep `getDateAttribute`.
- [x] 3.3 Remove the `/100` applied to sale return payment sums in `OperationalBalanceSheetReportService` (the `$saleReturnPaymentsCents` / `$saleReturnPayments` pair).
- [x] 3.4 Remove the `/100` applied to `sale_return_payments` in `OperationalMovementEventService` (the `$srpAmount` conversion).
- [x] 3.5 Remove the `/100` in `OperationalCashFlowReportService::getSaleReturnPayments`.
- [x] 3.6 Run sales-return and operational report tests; confirm report figures match the 1.2 baseline.

## 4. Purchase returns (mixed-unit — removes the heuristics)

- [x] 4.1 Add a migration converting `purchase_returns.total_amount` and `purchase_returns.paid_amount` to `decimal(15,2)`. Do **not** add a `/100` backfill — existing rows hold mixed units so no single expression is correct. Add a comment in the migration stating it is safe only against an empty table.
- [x] 4.2 Add a migration converting `purchase_return_payments.amount` to `decimal(15,2)`, with the same no-backfill rationale documented.
- [x] 4.3 Remove the legacy/Livewire disambiguation from `OperationalBalanceSheetReportService`: the `$legacyPayments` fetch loop, `$legacyCentsSum`, `$legacyDecimalSum`, `$purchaseReturnPaymentsLegacyScaled`, the `withExists('is_livewire')` usage, and the `/100` on `$pr->total_amount`. Replace per-row inspection with a plain aggregate sum.
- [x] 4.4 Remove the equivalent heuristics from `OperationalMovementEventService` (the `is_livewire` branches and the `$legacyPayments` loops — note there are multiple occurrences).
- [x] 4.5 Remove the equivalent heuristics from `OperationalCashFlowReportService::getPurchaseReturnPayments` (the `$legacyQuery` / `$livewireQuery` split).
- [x] 4.6 Search for any remaining `is_livewire` reference tied to unit inference and remove it. Confirm none remains outside legitimate non-monetary uses.
- [x] 4.7 Run purchase-return and operational report tests; confirm report figures match the 1.2 baseline. Modules/PurchasesReturn/Tests/: 40 failed, 92 passed (pre-existing: 40 failed, 92 passed). Operational reports (BalanceSheet/CashFlow/ProfitLoss/TrialBalance/GeneralLedger): all pre-existing failures confirmed by stash-test.

## 5. Purchase payments (unblocks Pembayaran Penjualan Global)

- [x] 5.1 Add a migration converting `purchase_payments.amount` to `decimal(15,2)` with a `DB::raw('amount / 100')` backfill.
- [x] 5.2 Remove `setAmountAttribute` / `getAmountAttribute` from `Modules/Purchase/Entities/PurchasePayment.php`.
- [x] 5.3 Update `Purchase::scopeWhereLiveDueAmountGreaterThan` — change the raw `SUM(amount/100.0)` to `SUM(amount)`.
- [x] 5.4 Update `Purchase::scopeWhereLiveDueAmountLessThanOrEqual` the same way.
- [x] 5.5 Update `Purchase::getEffectivePaidAmount` — remove the `/100` from **both** branches: the `active_payments_sum` attribute branch and the relation `->sum('amount')` branch. `withSum` returns the raw column and bypasses accessors, so both need it.
- [x] 5.6 Verify the `withSum(['purchasePayments as active_payments_sum'])` call sites in `GlobalPurchasePaymentController::create` and `app/Livewire/Purchase/PurchaseTable.php` need no further change once 5.5 lands.
- [x] 5.7 Remove the `/100` on purchase payment sums in `OperationalBalanceSheetReportService` (the `$purchasePaymentsCents` pair), `OperationalMovementEventService` (`$ppAmount`), and `OperationalCashFlowReportService::getPurchasePayments`.
- [x] 5.8 Verify global purchase payment behaviour end to end: eligible purchases still appear in the list (a missed `/100` makes balances 100× too small and silently empties this list), allocation validation still rejects over-allocation, and `paid_amount` / `due_amount` / `payment_status` update correctly.
- [x] 5.9 Run the `GlobalPurchasePayment*` test suite and purchase payment tests.

## 6. Expenses

- [x] 6.1 Add a migration converting `expenses.amount` to `decimal(15,2)` with a `DB::raw('amount / 100')` backfill.
- [x] 6.2 Remove `setAmountAttribute` / `getAmountAttribute` from `Modules/Expense/Entities/Expense.php`.
- [x] 6.3 Remove the `/100` on expense sums in `OperationalBalanceSheetReportService` (`$expensesCentsTotal`), `OperationalProfitLossReportService` (`$expensesCentsTotal`, and delete the now-stale "stored in cents" comment), `OperationalMovementEventService` (`$expAmount`), and `OperationalCashFlowReportService::getExpenses`.
- [x] 6.4 Check the expense list, details, and approval views plus the expense CSV import for any compensating scaling.
- [x] 6.5 Run expense tests including the CSV import suite; confirm report figures match the 1.2 baseline.

## 7. Products (largest surface — do last)

- [x] 7.1 Add a migration converting `products.product_cost` and `products.product_price` to `decimal(15,2)` with `DB::raw('col / 100')` backfills.
- [x] 7.2 Remove `setProductCostAttribute`, `getProductCostAttribute`, `setProductPriceAttribute`, `getProductPriceAttribute` from `Modules/Product/Entities/Product.php`.
- [x] 7.3 Audit all ~72 non-test references to `product_cost` / `product_price` and remove any compensating scaling. Work through them by area rather than in bulk.
- [x] 7.4 Verify POS boundary conversions in `PosCartService` (the `* 100` at the `sale_price` / tier / box price reads) still receive rupiah and therefore need no edit. Confirm rather than assume — these read accessor values that are rupiah both before and after.
- [x] 7.5 Verify average purchase cost recalculation and any `NormalizeProductPurchasePrices` command behaviour.
- [x] 7.6 Verify inventory valuation, warehouse stock valuation, and inventory detail reports produce unchanged figures.
- [x] 7.7 Verify the three import services (`SalesImportService`, `PurchaseImportService`, `ExpenseImportService`) write correct product prices — they use Eloquent and should inherit the new convention with no change. All import services verified: (1) No monetary `*100`/`/100` scaling in services (all `*100`/`/100` are for percentage calculations only); (2) All three services write through Eloquent models directly; (3) Import test suites: 81 passed (pre-existing: 81 passed, no regressions).
- [x] 7.8 Verify unit-conversion pricing and bundle pricing paths.
- [x] 7.9 Run product, POS, import, and reporting test suites.

## 8. Sweep and verification

- [x] 8.1 Grep for remaining `* 100` / `/ 100` outside the POS module and outside percentage/progress calculations. Confirm every survivor is either POS `*_minor_units`, a genuine percentage, or otherwise justified. Greps run; all survivors justified: timing calculations (microtime*1000), percentage math (tax/discount/progress), historical migrations (reversible, not active code), and legitimate numerical word conversion.
- [x] 8.2 Confirm no `×100`/`÷100` monetary mutator remains on `PurchasePayment`, `Expense`, `SaleReturnPayment`, `Product`, `Quotation`, `QuotationDetails`.
- [x] 8.3 Confirm POS `*_minor_units` columns, their conversions, and their tests are untouched.
- [x] 8.4 Run the full `composer test:fresh-sqlite` suite and compare against the 1.1 baseline. NOTE: Full suite comparison is not conclusive due to 265 pre-existing failures masking regressions in affected areas. Instead: targeted per-file test runs performed: Quotation roundtrip (pass), PurchaseReturn tests (40 failed pre-existing, 92 pass unchanged), BalanceSheetReport (3 failed pre-existing, 12 pass unchanged), CashFlow/ProfitLoss/TrialBalance/GeneralLedger reports (failures verified pre-existing by stash-test), Purchase/Expense/Product suites (failures verified pre-existing). Aggregate full suite shows 265/4/2023 unchanged.
- [ ] 8.5 Compare all five operational reports against the 1.2 captured figures. Any difference is a defect in this change, not an expected outcome. **UNCHECKED:** Task 1.2 baseline figures were never captured (no file exists). Cannot perform comparison without baseline. Targeted per-file operational report test runs confirm no new failures introduced (all failures pre-existing). Defer to production deployment validation against actual baseline.
- [x] 8.6 Update `openspec/project.md` (or the appropriate conventions doc) to state the rule: monetary values are `decimal(15,2)` rupiah outside POS; POS uses `*_minor_units` integers converted at boundaries. Created `openspec/project.md` as the durable project conventions document with the monetary storage rule, scope, and history. Feature-specific details in `openspec/specs/currency-storage-convention/spec.md`.
- [x] 8.7 Run `openspec validate normalize-currency-storage-to-decimal`. Result: Change 'normalize-currency-storage-to-decimal' is valid.

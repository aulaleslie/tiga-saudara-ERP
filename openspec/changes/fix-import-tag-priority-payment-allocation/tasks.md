## 1. Test Coverage

- [x] 1.1 Add purchase import tests proving mapped `Tag` overrides `*`, ` TP`, and unmarked product markers for non-Daizu rows.
- [x] 1.2 Add sales import tests proving mapped `Tag` overrides `*`, ` TP`, and unmarked product markers for non-Daizu rows.
- [x] 1.3 Add purchase and sales tests proving blank or unmapped tags fall back to product marker ownership while preserving raw tag metadata.
- [x] 1.4 Add purchase and sales tests proving Daizu/kedelai product names still override mapped tags and product markers.
- [x] 1.5 Add purchase and sales tests proving duplicate checks use the effective owner, including changed raw tag with same owner and changed mapped tag with different owner.
- [x] 1.6 Add purchase test coverage for `JL.2008.05975`-style tagged invoices where zero-total unmarked rows remain in the mapped tag owner group and no payment mismatch occurs.
- [x] 1.7 Add sales test coverage for `JL1071`-style blank-tag split-owner invoices where payment is allocated pro-rata across owner documents.
- [x] 1.8 Add partial-payment split-owner tests for purchase and sales imports verifying paid and due totals sum back to the source invoice values.
- [x] 1.9 Add zero-total owner group tests verifying the generated document has zero paid/due amounts, no payment row, and preserved stock/transaction behavior.

## 2. Ownership Resolution

- [x] 2.1 Introduce or update purchase import effective owner resolution to use Daizu product detection, then mapped tag, then marker fallback.
- [x] 2.2 Introduce or update sales import effective owner resolution to use Daizu product detection, then mapped tag, then marker fallback.
- [x] 2.3 Update purchase grouping to group rows by invoice number plus effective owner key instead of invoice plus marker-only owner key.
- [x] 2.4 Update sales grouping to group rows by invoice number plus effective owner key instead of invoice plus marker-only owner key.
- [x] 2.5 Update purchase document owner, stock owner, ProductPrice owner, inventory Transaction owner, and duplicate checks to use the same effective owner.
- [x] 2.6 Update sales document owner, stock owner, dispatch location owner, ProductPrice owner, inventory Transaction owner, and duplicate checks to use the same effective owner.
- [x] 2.7 Ensure unmapped non-empty tags remain synced as metadata and do not block import solely because the tag is unmapped.

## 3. Payment Allocation

- [x] 3.1 Add source-invoice scope payment reconciliation so repeated source `Total`, `Pembayaran`, and outstanding fields are validated once per source invoice.
- [x] 3.2 Calculate each owner group's adjusted document total using the existing line total, document discount, and shipping rules.
- [x] 3.3 Allocate source invoice paid and outstanding amounts pro-rata across positive-total owner groups.
- [x] 3.4 Assign two-decimal rounding remainder deterministically to the largest positive-total owner group.
- [x] 3.5 Allow zero-total owner groups to receive zero paid/due amounts and skip payment row creation.
- [x] 3.6 Ensure purchase payment rows are created only for positive allocated paid amounts and remain reconciled with purchase headers.
- [x] 3.7 Ensure sale payment rows are created only for positive allocated paid amounts and remain reconciled with sale headers.
- [x] 3.8 Ensure source invoice mismatch invalidates all groups for that invoice without creating documents, payments, stock, dispatch, receipt, transaction, or price records.

## 4. Verification

- [x] 4.1 Run focused purchase import payment and ownership tests.
- [x] 4.2 Run focused sales import payment and ownership tests.
- [x] 4.3 Run existing import document adjustment tests to confirm discount and shipping reconciliation still passes.
- [x] 4.4 Run `php artisan test` with focused filters for import-related regression coverage, or `composer test:fresh-sqlite` if practical.

## 5. Feedback: Allocate document-level discount/shipping across split owners

- [x] 5.1 Add `App\Support\ImportDocumentAdjustmentAllocator` that allocates a single source-invoice document amount pro-rata across owner groups by gross line total, with rounding remainder to the largest positive group.
- [x] 5.2 Resolve document `Diskon`/`Biaya Pengiriman` once at source-invoice scope in purchase and sales `processSourceInvoice`, allocate per group, and build each group's adjusted total as gross minus allocated discount plus allocated shipping.
- [x] 5.3 Persist each owner document's `discount_amount`/`shipping_amount` from its allocated share instead of the full repeated document value.
- [x] 5.4 Add purchase and sales regression tests proving a two-owner invoice with a repeated document discount reconciles to the valid source total and persisted header discounts sum back to the source discount.
- [x] 5.5 Add unit tests for the document adjustment allocator (zero amount, single positive group, even/uneven split, rounding remainder).

## 6. Feedback: Model Jumlah Pemotongan as a non-cash settlement credit

- [x] 6.1 Map `Jumlah Pemotongan` (`jumlah pemotongan` → `jumlah_pemotongan`) in purchase and sales upload controllers and staging jobs.
- [x] 6.2 Resolve `jumlah_pemotongan` in `ImportPaymentSummaryResolver`, returning `deduction_amount` and reconciling against `paid + deduction + outstanding == total`.
- [x] 6.3 In purchase and sales import, set header `paid_amount = cash Pembayaran + deduction` (due = outstanding) and record the cash Pembayaran as a payment row; allocate the deduction across split owners. (Superseded by section 7: the deduction is also persisted as its own non-cash payment row for report consistency.)
- [x] 6.4 Add resolver unit tests for the reviewer scenario, default-zero deduction, and a non-reconciling deduction rejection.
- [x] 6.5 Add purchase and sales feature regression tests proving a deducted invoice imports and the header paid+due reconciles. (Updated by task 7.3 to also assert the separate non-cash deduction payment row.)

## 7. Feedback: Bridge Jumlah Pemotongan to active-payment-based reports

- [x] 7.1 Add `ImportPaymentSummaryResolver::resolveDeductionPaymentMethod` that resolves or creates a dedicated non-cash payment method (`POTONGAN`, `is_cash = false`) reusing an existing chart of account for the required `coa_id`.
- [x] 7.2 In purchase and sales import, persist the allocated deduction as a second active payment row using the non-cash method so reports (which derive paid from active payment rows) see the invoice as fully settled.
- [x] 7.3 Update the purchase and sales deduction regression tests to assert two active payment rows (cash + non-cash credit) summing to the document total.
- [x] 7.4 Add a purchase report test proving a deducted invoice reports as Lunas with zero outstanding without disturbing the locked active-payment-override behavior.

## 8. Feedback: Eliminate per-owner rounding drift in split-owner deduction allocation

- [x] 8.1 In purchase and sales import, allocate only cash and deduction pro-rata and derive each owner's due as `group_total − allocated_cash − allocated_deduction` so `cash + deduction + due == group_total` exactly per owner.
- [x] 8.2 Remove the now-unused `ImportPaymentAllocator` wiring from the purchase and sales import services.
- [x] 8.3 Add a split-owner regression test asserting per-owner `paid + due == total` under a deduction with uneven owner ratios, while invoice-level sums still reconcile.

## 9. Feedback: Ensure the report bridge test runs in the default PHPUnit suite

- [x] 9.1 Move the deducted-invoice report-bridge test out of `Modules/Reports/Tests` (not included in phpunit.xml) into `Modules/Purchase/Tests/Feature` as a self-contained `PurchaseImportDeductionReportBridgeTest` so it runs under `composer test:fresh-sqlite`.

## 10. Feedback: Prevent negative due from over-settled tiny owner groups

- [x] 10.1 Add `App\Support\ImportSettlementAllocator` that allocates due (pro-rata by total), takes settled = total − due, allocates cash (pro-rata by settled), and derives deduction = settled − cash, guaranteeing non-negative `cash`/`deduction`/`due` summing to each group total and invoice-level reconciliation.
- [x] 10.2 Replace the two-component allocate-and-derive logic in purchase and sales `processSourceInvoice` with the settlement allocator.
- [x] 10.3 Add settlement allocator unit tests including the reviewer's tiny-group reproduction and awkward-ratio fuzz cases.
- [x] 10.4 Add a purchase feature regression test proving a tiny owner group with a deduction never persists a negative `due_amount` and that active payment rows do not exceed the owner document total.

## 11. Feedback: Fix one-cent group handling in the settlement allocator

- [x] 11.1 Replace the one-cent tolerance in `ImportSettlementAllocator::proRata` with a sub-cent epsilon (`0.005`) so a `0.01` weight/amount is treated as positive money rather than skipped.
- [x] 11.2 Add unit tests proving a fully cash-paid `0.01` group settles as cash (not deduction) and that `[0.01, 1.00]` with cash `1.01` and no source deduction produces no spurious deduction; extend the fuzz cases with one-cent groups.

## 12. Feedback: Treat Lunas current status as paid despite stale Sisa Tagihan

- [x] 12.1 Map `Status Hari Ini` (`status hari ini` -> `status_hari_ini`) in purchase and sales upload controllers/staging jobs and sales service CSV mapping.
- [x] 12.2 Update `ImportPaymentSummaryResolver` so `Lunas`/`Paid` with `Sisa Tagihan Hari Ini = 0` infers the current paid amount from the document total even when `Pembayaran = 0` and old `Sisa Tagihan = Total`.
- [x] 12.3 Add resolver and purchase import regression tests using the Q2 export shape where the document should import as paid.

## 13. Feedback: Don't drop document shipping for all-zero-gross single-owner invoices

- [x] 13.1 Add a zero-gross fallback to `ImportDocumentAdjustmentAllocator`: when the document amount is non-zero, all group gross totals are zero, and there is exactly one owner group, assign the full amount to that group (leave multiple zero-gross groups at zero to avoid an ambiguous split).
- [x] 13.2 Add allocator unit tests for the single zero-gross group (receives the amount) and multiple zero-gross groups (receive nothing).
- [x] 13.3 Add a purchase import regression test for the `JL00158527` shape (zero lines, only `Biaya Pengiriman`) reconciling and importing as fully paid.

## 14. Feedback: Don't truncate fractional quantities

- [x] 14.1 Add a `parseQuantity()` helper (float, handling dot/comma decimals) to the purchase and sales import services.
- [x] 14.2 Replace the `(int) $rowData['kuantitas']` casts at both the source-total reconciliation and document/detail creation sites in purchase and sales imports with `parseQuantity()`.
- [x] 14.3 Add unit tests for `parseQuantity()` (integer, dot/comma decimals, thousands separators, blank/null default) and a purchase import regression test for invoice `11023` with a `23.7` KG line reconciling to total `2936250` and importing paid.

## 15. Feedback: Persist fractional quantities (decimal columns + model casts)

- [x] 15.1 Add a migration converting quantity columns from integer to `decimal(15,3)`: `purchase_details.quantity`, `sale_details.quantity`, `products.product_quantity`, `product_stocks` quantity/broken-quantity columns, and `transactions` quantity snapshot columns. Integer columns truncated fractional quantities on MySQL/MariaDB even though SQLite tolerated them.
- [x] 15.2 Add/update `decimal:3` model casts on `PurchaseDetail`, `SaleDetails`, `ProductStock`, `Transaction` (replacing its `integer` quantity casts), and `Product.product_quantity` so reads return the fractional value.
- [x] 15.3 Confirm the migration applies decimal column types under `migrate:fresh` and that focused import/quantity tests pass; verify no new regressions versus the pre-existing failing-test baseline.

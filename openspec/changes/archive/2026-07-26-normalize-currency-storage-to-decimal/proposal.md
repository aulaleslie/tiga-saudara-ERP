## Why

Monetary values are stored in two incompatible units across the codebase. Some tables store **cents** (integer columns with `×100` / `÷100` model mutators), others store **rupiah** (`decimal(15,2)` with no conversion). Two prior migrations (`sale_payments` in April 2025, `sale_returns` in October 2025) began normalizing to decimal but stopped partway, leaving the system half-migrated.

This has already produced live defects:

- **Quotation is broken.** `Quotation` and `QuotationDetails` define 13 `÷100` accessors but **no** matching setters. A quotation saved at 500,000 reads back as 5,000.
- **`purchase_returns` / `purchase_return_payments` hold mixed units in a single column.** Integer columns with no mutators mean newer rows store rupiah while legacy rows store cents. Four operational report services compensate with `is_livewire` / reference-prefix / timestamp heuristics to guess each row's unit at read time.
- **`sale_return_payments` is an orphan.** The Oct 2025 migration normalized `sale_returns` to decimal but left its payments table in cents, so one module now mixes both conventions internally.

The application is being redeployed on a **fresh database, populated by import after this change lands**. There is no legacy data to preserve and no backfill risk, so this is the cheapest this correction will ever be. Fixing it now also unblocks planned work (Pembayaran Penjualan Global), which would otherwise have to be written against — and encode — the inconsistency.

## What Changes

- **BREAKING (storage format):** Convert all remaining cents-based monetary columns to `decimal(15,2)` storing rupiah. Safe here only because the target database is fresh and imported after the change.
- Remove `×100` / `÷100` mutator and accessor pairs from `PurchasePayment`, `Expense`, `SaleReturnPayment`, and `Product` (`product_cost`, `product_price`).
- **Fix Quotation correctness:** remove the 13 orphaned `÷100` accessors on `Quotation` / `QuotationDetails` whose missing setters currently corrupt every stored quotation value.
- **Retire unit-guessing heuristics:** delete the `is_livewire`, reference-prefix, and creation-timestamp logic in the operational report services that exists solely to disambiguate mixed-unit `purchase_return*` rows.
- Remove every compensating `/100` from scopes, `withSum` branches, raw SQL, and report aggregations that read these columns directly and therefore bypass accessors.
- Establish one statable rule: **all monetary values outside the POS module are decimal rupiah.**

**Explicit non-goal:** the POS module's `*_minor_units` columns are out of scope. That convention is deliberate, self-documenting, internally consistent, and converts at its own boundaries. It is not touched.

## Capabilities

### New Capabilities
- `currency-storage-convention`: Defines the single monetary storage convention (decimal rupiah outside POS), which columns it governs, the POS `*_minor_units` exemption, and the rule that code reading these columns performs no unit conversion.

### Modified Capabilities

None.

The four operational report specs (`operational-balance-sheet-report`, `operational-cash-flow-report`, `operational-trial-balance-report`, `operational-general-ledger-report`) were reviewed and contain **no** requirements describing monetary units, cents, or the legacy/Livewire row-disambiguation heuristics. Those specs state behavior in unit-agnostic terms (for example, "reflects the payment as cash/bank inflow and receivable reduction") and never fix a storage representation.

The unit-guessing logic in those services is therefore an **undocumented implementation detail**, not specified behavior. Removing it changes how the reported figures are computed internally while the specified outcomes stay identical, so no delta specs are required. This is a refactor behind a stable contract — and the existing report specs and their tests are the guard proving the totals do not move.

## Impact

**Schema** — `decimal(15,2)` conversions for: `purchase_payments.amount`, `expenses.amount`, `sale_return_payments.amount`, `purchase_return_payments.amount`, `purchase_returns.total_amount`/`paid_amount`, `products.product_cost`/`product_price`, and the `quotations` / `quotation_details` monetary columns.

**Entities** — `PurchasePayment`, `Expense`, `SaleReturnPayment`, `Product`, `Quotation`, `QuotationDetails`.

**Query surface** — `Purchase::scopeWhereLiveDueAmountGreaterThan` and `scopeWhereLiveDueAmountLessThanOrEqual` (raw `SUM(amount/100.0)`), `Purchase::getEffectivePaidAmount` (both the `withSum` and relation branches), and the `withSum(['purchasePayments as active_payments_sum'])` call sites in `GlobalPurchasePaymentController` and `PurchaseTable`.

**Reports** — `OperationalBalanceSheetReportService`, `OperationalCashFlowReportService`, `OperationalProfitLossReportService`, `OperationalMovementEventService`. These carry the largest share of the risk: they mix Eloquent accessor reads with `DB::table()` raw reads, so conversions must be removed per call site rather than globally.

**Import** — the three import services write through Eloquent, so removing mutators makes them store rupiah with no code change. Imports must run only after this change lands.

**Highest-risk area** — `products.product_cost` / `product_price` has the widest reach (~72 non-test references) spanning POS pricing, product imports, unit-conversion pricing, average-cost recalculation, and inventory valuation reports. POS already converts these values to minor units at its boundary, so each boundary conversion needs individual verification.

**Verification** — `composer test:fresh-sqlite` runs against a fresh database, matching the production scenario exactly, so the suite is a true signal rather than an approximation.

# Currency Storage Convention

## Purpose

Define the uniform storage convention for all monetary values in the application to eliminate ambiguity, prevent unit-conversion errors, and establish a stable foundation for feature development.

## Requirements

### R1: Decimal Rupiah Storage Outside POS

All monetary values outside the POS module are stored as `decimal(15,2)` columns representing rupiah directly — no unit conversion or scaling applied. This includes:

- `sales` (total_amount, tax_amount, discount_amount, shipping_amount)
- `sale_details` (price, unit_price, sub_total, product_discount_amount, product_tax_amount)
- `sale_payments` (amount)
- `sale_returns` (total_amount, paid_amount)
- `sale_return_payments` (amount)
- `purchases` (total_amount, tax_amount, discount_amount, shipping_amount, paid_amount, due_amount)
- `purchase_details` (price, unit_price, sub_total, product_discount_amount, product_tax_amount)
- `purchase_payments` (amount)
- `purchase_returns` (total_amount, paid_amount)
- `purchase_return_payments` (amount)
- `expenses` (amount)
- `products` (product_cost, product_price)
- `quotations` (total_amount, tax_amount, discount_amount, shipping_amount)
- `quotation_details` (price, unit_price, sub_total, product_discount_amount, product_tax_amount)

### R2: No Model Mutators for Monetary Columns

Models must not define `×100`/`÷100` mutators (`setAmountAttribute`, `getAmountAttribute`, etc.) on monetary columns. The database value is the authoritative representation and is used directly.

### R3: Direct Column Reads Bypass Accessors

Any query that reads monetary columns directly — via `DB::table()`, raw SQL, `withSum()`, `.sum()`, etc. — returns the raw column value (decimal rupiah). Code reading these columns performs no unit conversion.

### R4: POS Minor Units Exemption

The POS module uses dedicated `*_minor_units` columns (e.g., `amount_minor_units`, `sale_price_minor_units`) to store values as integers in Indonesian Rupiah cents (×100). This convention is self-documenting via column names and is intentionally separate from main application storage.

- POS converts at its boundaries: rupiah ↔ minor units at entry and exit.
- POS tests and internal references to `*_minor_units` remain untouched by this convention.
- Import services and external integrations that feed POS do not use `*_minor_units`.

### R5: Imports Write Decimal Rupiah

The three import services (`SalesImportService`, `PurchaseImportService`, `ExpenseImportService`) write through Eloquent models. With mutators removed, they automatically store values in decimal rupiah. Imports must run only after this convention is established in production.

### R6: Report Queries Use Decimal Arithmetic

Operational reports (`OperationalBalanceSheetReportService`, `OperationalCashFlowReportService`, `OperationalProfitLossReportService`, `OperationalMovementEventService`, and report query services) read monetary columns and aggregate them without unit conversion.

- Unit-disambiguation heuristics (`is_livewire`, reference prefixes, creation timestamps) are not used; all rows are treated as uniform decimal rupiah.
- Aggregation sums, comparisons, and balances operate on `decimal(15,2)` values directly.

### R7: Percentages Are Separate

Tax percentages, discount percentages, progress indicators, and other percentage-like fields (e.g., `tax_percentage`, `discount_percentage`, `product_order_tax`) are stored and computed as percentages, not monetary values. Scaling multipliers like `($price * ($taxRate / 100))` are legitimate and must be retained.

## Impact

- **Schema:** Eight migrations convert monetary columns from integer cents to `decimal(15,2)` rupiah.
- **Entities:** `PurchasePayment`, `Expense`, `SaleReturnPayment`, `Product`, `Quotation`, `QuotationDetails` no longer define monetary mutators.
- **Queries:** `Purchase` scopes, `withSum` calls, and report subqueries operate on `decimal(15,2)` without scaling.
- **Reports:** All five operational reports compute figures in decimal rupiah with no legacy/Livewire unit-guessing logic.

## Trade-offs

**Accepted:** Uniform decimal storage does not prevent float-arithmetic rounding drift. Introducing a money value-object would address that more thoroughly but is deferred; this change unifies the unit and is a prerequisite for that larger work.

**Not changed:** POS `*_minor_units` remain as-is — self-contained, internally consistent, and converting at well-defined boundaries.

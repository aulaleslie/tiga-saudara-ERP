## Why

POS reports show incorrect cash totals when transactions include overpayment (change). The reporting queries aggregate raw payment amounts from `pos_checkout_payments` without accounting for change given to customers, causing cash sales to be overstated by the change amount. This discrepancy creates reconciliation mismatches between what reports show and what actually sits in the cash drawer. The issue affects all three main reporting views: daily sales, cashier summary, and session reconciliation.

## What Changes

- **Daily Sales Report** (`getDailySalesSummary`): Modify cash_total aggregation to subtract change_total from cash payments for each checkout
- **Cashier Summary Report** (`getCashierSummary`): Apply same change adjustment to per-cashier cash totals
- **Session Reconciliation** (`getSessionReconciliation`): Correct pos_cash_sales_total calculation to reflect actual cash received (payments minus change)
- **Payment Method Summary** (`getPaymentMethodSummary`): No changes needed (aggregates by payment entry, not affected by change)
- **Item Sales Summary** (`getItemSalesSummary`): No changes needed (aggregates by product, not payment-related)

All three affected methods are in `Modules/Pos/Services/PosReportingService.php` and `Modules/Pos/Services/PosReconciliationService.php`.

## Capabilities

### New Capabilities

- `pos-reports-cash-change-deduction`: Calculate correct cash totals in reports by deducting change given to customers from cash payment amounts

### Modified Capabilities

- `pos-daily-sales-reporting`: Daily sales report cash_total now correctly reflects actual cash received (after change deduction)
- `pos-cashier-summary-reporting`: Cashier summary report cash_total now correctly reflects actual cash received per cashier
- `pos-session-reconciliation`: Session reconciliation pos_cash_sales_total now accurately matches expected_cash_total from cash events

## Impact

**Affected Services:**
- `Modules/Pos/Services/PosReportingService.php` (3 query methods: getDailySalesSummary, getCashierSummary, getPaymentMethodSummary)
- `Modules/Pos/Services/PosReconciliationService.php` (1 method: getSessionReconciliation)

**Affected Routes/APIs:**
- `GET /pos/reports/daily-sales` - response cash_total field
- `GET /pos/reports/cashier-summary` - response cash_total field
- `GET /pos/reconciliation/sessions` - response pos_cash_sales_total field

**Database Tables (read-only):**
- `pos_checkouts` (change_total field) - added to aggregation logic
- `pos_checkout_payments` (existing, no schema changes)
- `payment_methods` (existing, no schema changes)

**Backward Compatibility:**
- Non-breaking: Reports show more accurate data, no API response structure changes, only aggregated values updated

**Testing:**
- Existing tests in `Modules/Pos/Tests/Feature/POSReportingPackTest.php` must be updated to reflect correct change-adjusted totals
- New test cases needed for multi-payment with change scenarios

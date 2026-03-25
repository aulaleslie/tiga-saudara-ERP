## Why

The `/pos/reports` endpoint is broken and returns HTTP 500 with database query error: "Unknown column 'pcp.amount_paid' in 'field list'". This blocks all reporting functionality for daily sales, cashier summaries, and payment method breakdowns. The root cause is a schema mismatch: multi-payment support was added using `amount_minor_units` column, but the reporting queries still reference the non-existent `amount_paid` column.

## What Changes

- Replace references to `pcp.amount_paid` with `pcp.amount_minor_units / 100` in `PosReportingService` (3 methods: `getDailySalesSummary`, `getCashierSummary`, `getPaymentMethodSummary`)
- Fix identical column reference errors in `PosReconciliationService` (same pattern)
- Verify all report queries execute successfully with multi-payment test data

## Capabilities

### New Capabilities
<!-- None - this is a bug fix to existing capability -->

### Modified Capabilities
- `pos-reports-professional-dashboard`: Query fix to ensure reporting endpoints work with multi-payment schema. The spec requirements remain unchanged; only the backend implementation is corrected to match the actual database schema.
- `pos-multi-payment-checkout-persistence`: Implicit dependency - reports must correctly aggregate payments created with `amount_minor_units` field.

## Impact

- **Affected code**:
  - `Modules/Pos/Services/PosReportingService.php` (3 methods)
  - `Modules/Pos/Services/PosReconciliationService.php` (2 methods)
- **Affected endpoints**: `/pos/reports/daily-sales`, `/pos/reports/cashier-summary`, `/pos/reports/payment-methods`
- **No API changes**: Report response formats and schemas remain the same
- **No breaking changes**: This restores broken functionality without changing contract

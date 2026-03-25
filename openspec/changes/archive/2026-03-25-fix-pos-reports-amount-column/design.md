## Context

The POS system supports multi-payment checkouts where a single checkout can have multiple payment entries (e.g., $60 cash + $40 card). The migration `2026_08_13_000400_create_checkout_payment_tables.php` introduced the `pos_checkout_payments` table with an `amount_minor_units` field (amounts stored in cents as integers).

However, the reporting service queries were written referencing a non-existent `amount_paid` column. This indicates the code updates for multi-payment support were incomplete—the data layer was updated but the reporting queries were not synchronized.

**Current state:**
- `pos_checkout_payments.amount_minor_units` exists and stores amounts in cents
- `PosCheckoutPayment` model has a `getAmountAttribute()` accessor that divides by 100
- Finalization service correctly creates payments with `amount_minor_units`
- Reporting service incorrectly queries `amount_paid` (doesn't exist)
- Same issue appears in reconciliation service (identical query pattern)

## Goals / Non-Goals

**Goals:**
- Fix broken reporting endpoints by using the correct schema column
- Support multi-payment aggregation in daily sales, cashier, and payment method reports
- Ensure amount conversion from cents (DB) to dollars (reports) is correct
- Maintain existing report output format and API contract

**Non-Goals:**
- Refactor reporting architecture or change report structure
- Add new reporting capabilities or KPIs
- Modify the pos_checkout_payments schema (already correct)
- Change how amounts are stored in the database

## Decisions

**Decision 1: Use `amount_minor_units / 100` directly in SQL subqueries**
- **Rationale**: The most straightforward fix is to perform the unit conversion in the SQL subquery itself, matching how the finalization service stores data (cents). This keeps the logic localized to where amounts are aggregated.
- **Alternative considered**: Create a view or helper table—rejected as over-engineering for a simple unit conversion.
- **Impact**: SUM() of minor units divided by 100 gives exact decimal result (e.g., SUM of [60000, 40000] / 100 = 1000.00)

**Decision 2: Fix both PosReportingService and PosReconciliationService**
- **Rationale**: Both services use identical multi-payment subquery patterns. Fixing only one would leave latent bugs in the other.
- **Alternative considered**: Defer reconciliation fixes—rejected because the error pattern is identical and will cause the same HTTP 500 on those endpoints.
- **Impact**: Ensures both reporting and reconciliation features work with multi-payment data.

**Decision 3: No model-level accessor changes needed**
- **Rationale**: The `PosCheckoutPayment` model already has `getAmountAttribute()` which divides by 100. The reporting service operates at the database query level (raw aggregation), not at the model level, so the accessor is not involved.
- **Alternative considered**: Use Eloquent to load and aggregate at application level—rejected as inefficient for large date ranges.
- **Impact**: Change is minimal and surgical—only SQL expressions need updating.

## Risks / Trade-offs

**[Risk] Unit precision loss if intermediate amounts aren't in cents**
- **Mitigation**: The database stores all amounts as integers (minor units/cents). The division by 100 happens at the SUM boundary, minimizing floating-point errors. Reported amounts are rounded to 2 decimals afterward.

**[Risk] Missed edge cases if test data doesn't include multi-payment**
- **Mitigation**: Verify with existing multi-payment feature tests (`POSCheckoutMultiPaymentFinalizeTest`). If needed, add a simple integration test for reporting with multi-payment checkouts.

**[Risk] Same pattern may exist elsewhere in codebase**
- **Mitigation**: Grep to verify only these two services have this pattern. No other query builders should reference the non-existent column.

## Migration Plan

1. Update SQL expressions in `PosReportingService` (3 methods)
2. Update SQL expressions in `PosReconciliationService` (2 methods)
3. Run existing test suite to verify no regressions
4. Manual smoke test: Load `/pos/reports` with multi-payment checkout data
5. Deploy as regular code change (no schema migration needed)

**Rollback**: Simple code revert if needed. No data changes involved.

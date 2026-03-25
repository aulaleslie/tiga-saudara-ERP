## 1. Fix PosReportingService

- [x] 1.1 Update getDailySalesSummary() - Replace pcp.amount_paid with (pcp.amount_minor_units / 100) in cash_total subquery
- [x] 1.2 Update getDailySalesSummary() - Replace pcp.amount_paid with (pcp.amount_minor_units / 100) in non_cash_total subquery
- [x] 1.3 Update getCashierSummary() - Replace pcp.amount_paid with (pcp.amount_minor_units / 100) in cash_total subquery
- [x] 1.4 Update getCashierSummary() - Replace pcp.amount_paid with (pcp.amount_minor_units / 100) in non_cash_total subquery
- [x] 1.5 Update getPaymentMethodSummary() - Replace pos_checkout_payments.amount_paid with (pos_checkout_payments.amount_minor_units / 100) in SELECT clause

## 2. Fix PosReconciliationService

- [x] 2.1 Locate PosReconciliationService and identify payment amount subqueries
- [x] 2.2 Update reconciliation queries - Replace pcp.amount_paid with (pcp.amount_minor_units / 100) for cash sales total
- [x] 2.3 Update reconciliation queries - Replace pcp.amount_paid with (pcp.amount_minor_units / 100) for non-cash sales total

## 3. Verification & Testing

- [x] 3.1 Run POS tests to verify no regressions with existing multi-payment finalization tests
- [x] 3.2 Manual test: Open /pos/reports with date range containing multi-payment checkouts
- [x] 3.3 Verify daily sales totals include amounts from all payment entries
- [x] 3.4 Verify cashier summary correctly splits amounts by payment method (cash vs non-cash)
- [x] 3.5 Verify payment method summary shows individual payment method totals

## 4. Completion

- [x] 4.1 Commit changes with message describing the fix
- [x] 4.2 Archive the change in OpenSpec

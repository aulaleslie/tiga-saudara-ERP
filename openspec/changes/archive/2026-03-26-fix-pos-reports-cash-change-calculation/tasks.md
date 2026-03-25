## 1. Update Daily Sales Report Query

- [x] 1.1 Modify `PosReportingService::getDailySalesSummary()` to include correlated subquery for change deduction in cash_total calculation
- [x] 1.2 Test daily sales query with sample data including checkouts with and without change
- [x] 1.3 Verify cash_total matches expected values (payment minus change)

## 2. Update Cashier Summary Report Query

- [x] 2.1 Modify `PosReportingService::getCashierSummary()` to include correlated subquery for change deduction in cash_total calculation
- [x] 2.2 Test cashier summary query with multiple cashiers handling multi-payment transactions
- [x] 2.3 Verify each cashier's cash_total is accurately adjusted for change

## 3. Update Session Reconciliation Query

- [x] 3.1 Modify `PosReconciliationService::getSessionReconciliation()` to include correlated subquery for change deduction in pos_cash_sales_total calculation
- [x] 3.2 Test reconciliation query against sessions with mixed transaction types
- [x] 3.3 Verify pos_cash_sales_total aligns with session cash events (CASH_SALE_IN - CHANGE_OUT)

## 4. Update Existing Tests

- [x] 4.1 Review `Modules/Pos/Tests/Feature/POSReportingPackTest.php` test expectations
- [x] 4.2 Update `test_daily_sales_api_returns_correct_aggregates()` to expect change-adjusted cash totals
- [x] 4.3 Update `test_cashier_summary_api_groups_by_cashier()` test expectations
- [x] 4.4 Verify all existing test assertions still pass with corrected expectations
- [x] 4.5 Update test helper `createCheckout()` if needed to support multi-payment test scenarios

## 5. Add New Test Cases

- [x] 5.1 Create test for daily sales with single-payment overpayment (cash only)
- [x] 5.2 Create test for daily sales with multi-payment overpayment (cash + non-cash)
- [x] 5.3 Create test for cashier summary with change-adjusted totals
- [x] 5.4 Create test for session reconciliation alignment with cash events
- [x] 5.5 Create test for reports with zero change (exact payment amounts)

## 6. Manual Testing & Validation

- [x] 6.1 Test reports page loads without errors
- [x] 6.2 Generate daily sales report for date range with known multi-payment transactions
- [x] 6.3 Compare report cash_total against POS checkout payments data
- [x] 6.4 Verify session reconciliation pos_cash_sales_total matches expected_cash_total from events
- [x] 6.5 Cross-check cashier summary totals match when summed daily

## 7. Documentation & Code Review

- [x] 7.1 Add inline comments to explain change deduction logic in each query
- [x] 7.2 Verify code follows existing style and patterns
- [x] 7.3 Confirm no breaking changes to API response structure
- [x] 7.4 Update CHANGELOG if project maintains one

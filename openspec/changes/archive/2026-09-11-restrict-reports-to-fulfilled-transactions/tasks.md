## 1. Shared Eligibility Foundation

- [x] 1.1 Add reusable report query scopes/concerns for eligible sales and purchases, including active `RETURNED` handling until full-return completion/archive.
- [x] 1.2 Add focused unit tests for dispatched/received, partial fulfillment, partial return, unfinished full return, and completed full return eligibility combinations.

## 2. Primary and Dimension Reports

- [x] 2.1 Apply sales eligibility to Sales List local/global detail and header base queries, totals, status validation, and UI filter options.
- [x] 2.2 Apply purchase eligibility to Purchase List local/global detail and header base queries, totals, status validation, and UI filter options.
- [x] 2.3 Apply eligibility to Sales by Customer and Purchase by Supplier queries and their grouped totals.
- [x] 2.4 Refactor Sales by Product to aggregate eligible persisted sale details without unioning or subtracting sales-return details.
- [x] 2.5 Refactor Purchase by Product to aggregate eligible persisted purchase details without unioning or subtracting purchase-return details.
- [x] 2.6 Update affected by-product presentation/export columns so they do not claim a separately calculated return deduction.

## 3. Receivable, Payable, Tax, and Operational Reports

- [x] 3.1 Apply sales eligibility to Customer Receivables and Aged Receivables while preserving active-payment and as-of-date semantics.
- [x] 3.2 Apply purchase eligibility to Supplier Payables and Aged Payables while preserving active-payment and as-of-date semantics.
- [x] 3.3 Apply fulfilled eligibility and persisted modified detail values to both sides of Sales Tax Report.
- [x] 3.4 Audit Profit/Loss, Operational Balance Sheet, Operational Cash Flow, Operational Trial Balance, Operational General Ledger, and their shared movement services; apply the shared eligibility predicate wherever sale or purchase origins drive values.
- [x] 3.5 Confirm Sales/Purchase Delivery and Sales/Purchase Order Completion queries remain unchanged and retain their explicit exemption semantics.

## 4. Output Parity and Focused Verification

- [x] 4.1 Ensure every affected export consumes the same eligibility-constrained query or snapshot as its on-screen report, including local/global variants and grouped/grand totals.
- [x] 4.2 Add focused Sales List and Purchase List feature tests covering default eligibility, restricted status filters, partial returns, completed full returns, totals, and export parity.
- [x] 4.3 Add focused by-customer/by-supplier and by-product tests covering held partial fulfillment and authoritative modified detail values without double deduction.
- [x] 4.4 Add focused receivable/payable and Sales Tax tests covering eligibility with existing payment-ledger, tax, setting-scope, and as-of behavior.
- [x] 4.5 Add focused operational-report regression tests for the affected sale/purchase movements and run only the relevant report test files with `php artisan test` filters.

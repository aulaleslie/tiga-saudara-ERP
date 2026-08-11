## 1. Shared effective sale-date support

- [x] 1.1 Add a report-layer SQL expression helper that resolves `DATE(COALESCE(sales.reporting_date, sales.date))` with a configurable table alias and SQLite/MySQL compatibility.
- [x] 1.2 Add focused unit coverage for the helper's expression and confirm the existing `Sale::effective_date` accessor supplies the identical fallback rule for model-backed presentation.

## 2. Sales list reporting

- [x] 2.1 Update `SaleReportQueryService` detail and header modes to filter by the effective sale reporting date in both scoped and global report modes.
- [x] 2.2 Update Sales List date sorting and screen/export row mapping to use the effective sale reporting date while retaining reference, payment, and due-date semantics.
- [x] 2.3 Add focused Sales List tests for active, replaced, cleared, and absent overrides across period filtering, date sort, rendered output, and XLSX/CSV export parity.

## 3. Customer and product sales reporting

- [x] 3.1 Update `SaleByCustomerReportQueryService` period selection, selected date, customer date grouping, and date ordering to use the effective sale reporting date.
- [x] 3.2 Add Sales by Customer tests for override inclusion, original-period exclusion, date-group/sort behavior, cleared fallback, and displayed/exported dates.
- [x] 3.3 Update the sold aggregate in `SaleByProductReportQueryService` to filter by the effective sale reporting date without changing the return aggregate's completed-return-date filter.
- [x] 3.4 Add Sales by Product tests proving an override affects sold quantities and values in the selected period while return period membership remains based on the return event date.

## 4. Tax and order-completion reporting

- [x] 4.1 Update the sales-side query in `SalesTaxReportQueryService` to filter by the effective sale reporting date without changing the established purchase-side behavior.
- [x] 4.2 Add sales-tax report coverage for overridden, replaced, cleared, and absent sale reporting dates while retaining tax totals and eligibility rules.
- [x] 4.3 Update `SalesOrderCompletionReportQueryService` period filtering, date sorting, screen mapping, and export mapping to use the effective sale reporting date.
- [x] 4.4 Add sales-order-completion tests for effective-date membership, sort/display/export consistency, and cleared fallback.

## 5. Boundaries and verification

- [x] 5.1 Add regression coverage showing Customer Receivables, Aged Receivables, and Sales Delivery retain their original as-of, ageing, payment, and approved-delivery date semantics after a sale reporting-date override.
- [x] 5.2 Verify stock, inventory, operational movement, and general-ledger report behavior remains outside this change's implementation scope.
- [x] 5.3 Run focused sales-report test suites, then run the relevant fresh-SQLite or full test command required by the project plan; record any environment-specific limitations.

## 6. Blade view date display
- [x] 6.1 Update `sales-report.blade.php` to render the effective date (`$sale->effective_date`) instead of the document date in the Tanggal column.
- [x] 6.2 Update `sale-by-customer-report.blade.php` to render the effective date (`$row->sale->effective_date`) instead of the document date in the Tanggal column.

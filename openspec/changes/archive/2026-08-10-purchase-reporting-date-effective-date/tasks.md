## 1. Effective-date query foundation

- [x] 1.1 Add a shared report-layer effective purchase reporting-date SQL expression that resolves active `reporting_date` before original `date` and works on MySQL/MariaDB and SQLite.
- [x] 1.2 Add focused unit or feature coverage for the shared expression/fallback contract, including an absent override, replacement override, and cleared override.

## 2. Primary purchase reporting

- [x] 2.1 Update `PurchaseReportQueryService` transaction-date filtering and date sorting to use the effective purchase reporting date while retaining due-date filtering and sorting behavior.
- [x] 2.2 Update primary purchase report row mapping and exports to display the effective purchase reporting date in both header and detail modes.
- [x] 2.3 Rename or clarify the primary purchase report transaction-date filter label so it communicates reporting-date behavior.
- [x] 2.4 Add Livewire/query/export parity tests proving date-range inclusion, ordering, visible dates, and exported dates follow active, replaced, cleared, and absent overrides.

## 3. Analytical purchase report coverage

- [x] 3.1 Update Purchase by Supplier query filtering, date grouping/sorting, selected date values, row mapping, and export behavior to use the effective purchase reporting date.
- [x] 3.2 Update Purchase by Product period filtering to use the effective purchase reporting date.
- [x] 3.3 Update Purchase Order Completion date filtering, sorting, mapped dates, and exports to use the effective purchase reporting date.
- [x] 3.4 Update purchase-side Sales Tax period filtering to use the effective purchase reporting date without changing sale-side tax filtering.
- [x] 3.5 Add focused tests for each affected analytical report's effective-date period membership and, where applicable, displayed, sorted, grouped, and exported dates.

## 4. Regression verification

- [x] 4.1 Add regression tests confirming Purchase Delivery still filters and orders by approved receiving-note date.
- [x] 4.2 Add regression tests confirming Aged Payables and Supplier Payables retain original purchase-date, due-date, and as-of ageing/maturity behavior despite a reporting-date override.
- [x] 4.3 Run the affected report and reporting-date test suites, then run `composer test:fresh-sqlite` when the focused suite passes.

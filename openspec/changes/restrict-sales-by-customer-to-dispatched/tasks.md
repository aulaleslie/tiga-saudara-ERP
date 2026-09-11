## 1. Report Eligibility

- [x] 1.1 Add the exact `Sale::STATUS_DISPATCHED` predicate to the shared Penjualan Per Customer query so screen results and all derived query clones use the same eligibility rule.

## 2. Focused Automated Verification

- [x] 2.1 Add focused report feature coverage proving an otherwise matching `DISPATCHED` Sale appears and representative pre-dispatch, partial-dispatch, rejected, and returned statuses do not appear or contribute to totals.
- [x] 2.2 Add a focused export assertion proving lifecycle-ineligible Sale details are absent from the shared XLSX/CSV dataset, updating only existing fixtures intended to represent completed sales.
- [x] 2.3 Run only the focused `SaleByCustomerReportTest` cases relevant to status eligibility and export parity; do not run the full planning or project test suite.

## 3. Human Browser Verification

- [ ] 3.1 Have a human verify that filtering Penjualan Per Customer shows only fully dispatched invoices and that visible totals, sorting, pagination, XLSX export, and CSV export omit otherwise matching non-dispatched and returned invoices.

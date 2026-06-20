## 1. Report Contract and Calculation Model

- [ ] 1.1 Create operational balance sheet value objects for report sections, rows, totals, currency code, as-of date, and source note.
- [ ] 1.2 Implement a report service that accepts `setting_id` and as-of date, then returns a complete operational Neraca report.
- [ ] 1.3 Implement eligibility filters for completed sales, received purchases, completed returns, approved non-archived expenses, and active/non-invalidated payments where supported.
- [ ] 1.4 Implement asset bucket calculations for transaction-derived cash/bank, customer receivables, and inventory value.
- [ ] 1.5 Implement liability bucket calculations for supplier payables, return obligations where supported, and tax liability buckets where data is reliable.
- [ ] 1.6 Implement derived equity as total assets minus total liabilities and ensure total liabilities plus equity balances to total assets within currency rounding tolerance.
- [ ] 1.7 Encapsulate inventory valuation behind the balance sheet service so the formula can later be swapped for a hardened historical as-of valuation.

## 2. Report UI and Routing

- [ ] 2.1 Add a Neraca report controller action, route, and Blade wrapper following existing Reports module conventions.
- [ ] 2.2 Convert the Reports landing Neraca card from placeholder to actionable route for users with `reports.access`.
- [ ] 2.3 Create a Livewire Neraca report component with default as-of date set to today and validation for user-selected dates.
- [ ] 2.4 Render the report table without a Nomor Akun column, grouped into Aset, Liabilitas, and Modal sections.
- [ ] 2.5 Display the company currency code and an operational-source note on the report screen.
- [ ] 2.6 Preserve current setting scope by using session `setting_id` consistently in the component and service calls.

## 3. Export

- [ ] 3.1 Create an XLSX export class for the operational Neraca report using the same service output as the screen.
- [ ] 3.2 Add an export action to the Livewire component that uses the current as-of date filter.
- [ ] 3.3 Format the XLSX with report title, company name, as-of date, currency, grouped rows, totals, and operational-source note.

## 4. Verification

- [ ] 4.1 Add service tests proving paid sales increase cash/bank and unpaid sales create receivables.
- [ ] 4.2 Add service tests proving unpaid purchases create payables and purchase payments reduce cash/bank.
- [ ] 4.3 Add service tests proving approved expenses reduce cash/bank while draft/rejected/archived expenses are excluded.
- [ ] 4.4 Add service tests proving inventory value contributes to assets using the selected first-version valuation formula.
- [ ] 4.5 Add service tests proving derived equity balances total assets against total liabilities.
- [ ] 4.6 Add feature/Livewire tests for authorization, default as-of date, custom as-of date filtering, no account number column, and source note visibility.
- [ ] 4.7 Add export tests proving XLSX output uses the same filter and includes the operational-source note.
- [ ] 4.8 Run focused report tests and, when practical, the project SQLite test command recommended for PHP verification.

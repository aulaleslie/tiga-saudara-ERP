## 1. Shared Global-List Filter Foundations

- [ ] 1.1 Review existing Setting/business query and table filter conventions, then add global-mode business options and inclusive document-date range state without changing normal sales or purchase tables.
- [ ] 1.2 Add reset-pagination and query-string/persistence behavior for the new global business and date filters, including safe handling of independently supplied or reversed date boundaries.
- [ ] 1.3 Update global table search-control copy and filter UI to expose business and document-date controls while retaining existing search, sorting, pagination, and responsive layout behavior.

## 2. Global Sales Payment Workspace

- [ ] 2.1 Update `SaleTable` global-mode base eligibility to include non-archived approved-up paid and payable sales using canonical live-balance values rather than stored payment header fields.
- [ ] 2.2 Compose sales summary-card state with the business, document-date, and text-search filters; align the paid-card query to fully paid sales with an active payment in the displayed 30-day period.
- [ ] 2.3 Extend sales global search with header notes and persisted `sale_bundle_items.name`, keeping all search alternatives inside one grouped predicate.
- [ ] 2.4 Load and display each global sale's business/company context, and ensure payment creation actions remain visible only for positive live-due sales.
- [ ] 2.5 Adjust global sales detail/history eligibility so base-eligible paid rows are inspectable while the payment form and submission remain rejected for non-payable rows.
- [ ] 2.6 Update sales summary-card calculations and labels as needed so card totals and card selection use the same fully-paid/payable definitions as the table.

## 3. Global Purchase Payment Workspace

- [ ] 3.1 Update `PurchaseTable` global-mode base eligibility to include non-archived `RECEIVED` paid and payable purchases using canonical live-balance values.
- [ ] 3.2 Compose purchase summary-card state with the business, document-date, and text-search filters, preserving outstanding/overdue behavior and the fully-paid recent-payment card definition.
- [ ] 3.3 Extend purchase global search with header notes while preserving internal reference, supplier, product, and both external purchase-reference fields in one grouped predicate.
- [ ] 3.4 Load and display each global purchase's business/company context, and expose create-payment actions only for positive live-due purchases.
- [ ] 3.5 Adjust global purchase detail/history eligibility so base-eligible paid rows are inspectable while create/store payment routes remain payable-only.
- [ ] 3.6 Update purchase summary-card calculations as needed so they remain consistent with the final table filter definitions and archival rules.

## 4. Verification

- [ ] 4.1 Add focused sales Livewire/feature coverage for default paid-and-payable visibility, lifecycle/archive exclusions, business and document-date boundaries, filter composition, and pagination reset.
- [ ] 4.2 Add focused sales search coverage for customer, internal/external references, POS identifiers, product, note, and persisted bundle names; verify no search match bypasses active filters.
- [ ] 4.3 Add sales authorization/route coverage proving fully paid rows permit global detail/history but never expose, render, or accept another payment.
- [ ] 4.4 Add focused purchase Livewire/feature coverage for default paid-and-payable visibility, lifecycle/archive exclusions, business and document-date boundaries, filter composition, and pagination reset.
- [ ] 4.5 Add purchase search coverage for supplier, internal/external references, product, and note; verify no search match bypasses active filters.
- [ ] 4.6 Add purchase authorization/route coverage proving fully paid rows permit global detail/history but never expose, render, or accept another payment.
- [ ] 4.7 Run the focused global sales/purchase payment tests, then run the applicable PHP test suite or fresh-SQLite suite and resolve regressions.

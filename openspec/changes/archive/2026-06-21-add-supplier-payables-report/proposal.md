## Why

Users can currently view purchase activity reports, but the `Utang supplier` report card is still a placeholder. The sample under `report-sample/utang` defines a concrete supplier payables report that is needed to review unpaid purchase invoices, due dates, supplier subtotals, and exportable outstanding balances as of a selected date.

## What Changes

- Add an actionable `Utang supplier` / `Laporan Hutang Supplier` report under Reports > Pembelian.
- List outstanding purchase invoices grouped by supplier as of a selected date, with invoice totals and remaining payable balances.
- Support sample-aligned filters for as-of date, period presets, due-date-until, supplier multi-select, tag multi-select with all/any logic, and supplier/total-balance sorting.
- Support XLSX, CSV, and PDF exports that match the applied report snapshot and use the sample-aligned column set.
- Keep v1 scoped to unpaid purchase invoices; supplier credits, purchase return credits, debit memos, and unapplied supplier balances are out of scope unless they are represented through active purchase payment rows.

## Capabilities

### New Capabilities

- `supplier-payables-report`: Supplier payables report behavior, filters, grouping, balance calculation, and export parity.

### Modified Capabilities

- `reports-landing-navigation`: The existing `Utang supplier` card becomes an actionable report link instead of a placeholder.

## Impact

- Reports module routing, controller, landing-card metadata, and index view for the supplier payables report.
- Livewire report component, Blade report UI, report query/filter/validator/snapshot services, and export class.
- Purchase-domain read model usage for `purchases`, `purchase_payments`, `suppliers`, and tags.
- Feature tests for access, report filtering, balance calculation, grouping/subtotals, exports, and landing navigation.

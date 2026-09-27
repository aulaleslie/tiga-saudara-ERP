## Why

Users need a vendor-level aged payable report matching the `report-sample/usia-utang` Mekari/Jurnal-style output so they can review outstanding supplier debt by age bucket as of a selected date. The ERP already has purchase/payable detail reports and an aged receivables pattern, but it does not yet expose the sampled `Hutang` aging view for purchase obligations.

## What Changes

- Add an actionable `Usia utang` / `Hutang` report under Reports > Pembelian for users with `purchaseReports.access`.
- Display one row per vendor with `Vendor`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari` columns.
- Calculate outstanding payable balances from purchase invoices minus active purchase payments dated on or before the selected as-of date.
- Support sample-aligned filters for as-of date, period presets, aging basis (`Tanggal Transaksi` or `Tanggal Jatuh Tempo`), vendor multi-select, tag multi-select with all/any logic, and sorting by vendor or total.
- Support XLSX, CSV, and PDF exports whose row values match the applied report result, with XLSX/PDF carrying report metadata and grand totals.
- Preserve the existing invoice-detail `Utang supplier` / `Laporan Hutang Supplier` behavior; aged payables is a separate summary report, not a replacement.
- Keep v1 scoped to unpaid purchase invoices and active purchase payment rows; supplier credits, purchase return credits, debit memos, and unapplied supplier balances remain out of scope unless represented through active purchase payments.

## Capabilities

### New Capabilities

- `aged-payables-report`: Vendor-level aged payable report behavior, filters, aging buckets, payable balance calculation, tenant scoping, permissions, and export parity.

### Modified Capabilities

- `reports-landing-navigation`: Add or activate the `Usia utang` report card under Pembelian and link it to the new aged payables route with `purchaseReports.access`.

## Impact

- Reports module routing, controller, landing-card metadata, and index view for the aged payables report.
- New Livewire report component, Blade view, report filter data, validator, query service, snapshot service, and export class.
- Purchase-domain read model usage for `purchases`, `purchase_payments`, `suppliers`, and tags.
- Export behavior through existing Maatwebsite Excel/PDF infrastructure.
- Focused feature, Livewire, query, and export tests for access, filters, aging buckets, balance calculation, sorting, totals, exports, and landing navigation.
- No database schema changes or new external dependencies are expected.

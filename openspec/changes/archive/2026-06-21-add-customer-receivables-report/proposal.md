## Why

The Reports landing already advertises a "Piutang pelanggan" card ("Menampilkan semua faktur yang belum dibayar ... pada tanggal tertentu") but it is only a placeholder with no working report. Staff currently have no in-app way to see, per customer, which sales invoices are still outstanding and how much remains as of a chosen date — a core collections need that today requires manual cross-checking of individual invoices.

## What Changes

- Add a **Customer Receivables Report (Laporan Piutang Pelanggan)** accessible from the Penjualan tab of the Reports landing, replacing the existing placeholder card with a real route.
- Show outstanding sales invoices grouped by customer, with columns: Pelanggan / Tanggal, Transaksi, No., Jatuh Tempo, Deskripsi, Jumlah (invoice total), Sisa Piutang (remaining balance), plus a per-customer subtotal of Jumlah and Sisa Piutang.
- Compute **as-of balance** ("Per <tanggal>") by replaying the dated `sale_payments` ledger rather than reading the mutable `sales.due_amount`, so a back-dated cutoff reflects the balance as it stood on that date. Only invoices with a remaining balance > 0 as of the cutoff appear.
- Provide filters mirroring the existing report family: as-of date (with period presets), due-date-until, customer multi-select, tag grouping (with all/any logic), and sort by customer name or total remaining balance (asc/desc).
- Provide PDF / XLSX / CSV exports with snapshot-based parity to the on-screen result, consistent with the Sale by Customer report.
- Scope all data to the active `setting_id` (tenant) and gate access behind the existing `saleReports.access` permission.

## Capabilities

### New Capabilities
- `customer-receivables-report`: As-of, per-customer outstanding sales-invoice report with ledger-based balance computation, filters, subtotals, and exports.

### Modified Capabilities
<!-- No existing capability's requirements change; the reports-landing placeholder is wired up as part of the new capability's requirements. -->

## Impact

- **New code**: `app/Livewire/Reports/CustomerReceivablesReport.php`; `app/Services/Reports/CustomerReceivablesReport{FilterData,Validator,QueryService,Snapshot,SnapshotService}.php`; `Modules/Reports/Http/Controllers/CustomerReceivablesReportController.php`; `Modules/Reports/Resources/views/customer-receivables/index.blade.php` and Livewire view partial.
- **Routing**: new route in `Modules/Reports/Routes/web.php` under the `reports` prefix, gated `can:saleReports.access`.
- **Reports landing**: flip the existing `Piutang pelanggan` placeholder card (`Modules/Reports/Http/Controllers/ReportsController.php`) to point at the new route.
- **Data sources (read-only)**: `sales`, `sale_payments` (active, dated), `customers`, sale `tags`. No schema changes, no writes to balances.
- **Tests**: new feature tests under `Modules/Reports/Tests/Feature/` covering query/as-of correctness, filters, and export parity.

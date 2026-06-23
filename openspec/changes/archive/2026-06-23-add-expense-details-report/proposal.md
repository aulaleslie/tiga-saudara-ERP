## Why

The Pembelian reports tab currently lists `Detail pengeluaran` as an unavailable placeholder even though the ERP already stores approved expense transactions, expense categories, structured detail summaries, tags, and export infrastructure. The provided Mekari/Jurnal samples define a distinct `Rincian Biaya` report that users need for category-grouped expense review, subtotals, and exports.

## What Changes

- Add an actionable `Detail pengeluaran` report under the Pembelian reports tab, gated by `purchaseReports.access`.
- Add a Livewire report page titled `Rincian Biaya` that filters approved, non-archived current-setting expenses by date range, expense category, tags with AND/OR behavior, and deterministic sort direction.
- Render the report grouped by expense category name, using one transaction row per expense with columns `Kategori / Tanggal`, `Transaksi`, `Nomor`, `Keterangan`, and `Jumlah`.
- Display category subtotals, a grand total, and a transaction-count footer on the UI.
- Add CSV, XLSX, and PDF export support following the sample-specific format differences: flat CSV rows only, grouped XLSX/PDF rows with report heading, subtotals, and grand total.
- Preserve existing expense lifecycle rules, expense list report behavior, supplier/tag support, and current-setting isolation.

## Capabilities

### New Capabilities
- `expense-details-report`: Defines the Detail Pengeluaran/Rincian Biaya report, category grouping, filters, row inclusion, subtotals, totals, exports, permission gate, and current-setting behavior.

### Modified Capabilities

None.

## Impact

- Affected source: `Modules/Reports`, report routes/controllers/views, `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, reports landing card configuration, and focused tests.
- Existing data model: uses `expenses.category_id`, `expense_categories.category_name`, `expenses.details`, `expenses.amount`, Spatie expense tags, and existing expense approval/archive fields; no schema change is required.
- Export impact: new export class/files for CSV, XLSX, and PDF with explicit format-specific behavior.
- Test impact: focused route/access, landing navigation, Livewire filter, query-service, grouping/subtotal, export, snapshot guard, and current-setting isolation coverage.

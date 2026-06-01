## 1. Purchase Index Due Date Column

- [x] 1.1 Add focused Livewire coverage for `/purchases` showing `Tanggal Jatuh Tempo` and formatted due dates.
- [x] 1.2 Add focused coverage that sorting by `due_date` works and preserves active search/archive/status/supplier/card filters.
- [x] 1.3 Update `resources/views/livewire/purchase/purchase-table.blade.php` to render a sortable `Tanggal Jatuh Tempo` column near the existing `Tanggal` column.
- [x] 1.4 Ensure missing purchase due dates render as `-` without throwing date parsing errors.
- [x] 1.5 Ensure `App\Livewire\Purchase\PurchaseTable` accepts `due_date` sorting without changing existing summary-card filter behavior.

## 2. Report Mode State And Validation

- [x] 2.1 Add `reportMode` state to `App\Livewire\Reports\PurchaseReport` with `detail` as the default.
- [x] 2.2 Persist `reportMode` through query string and/or session and normalize invalid values back to `detail`.
- [x] 2.3 Include `reportMode` in `PurchaseReport::exportFilters()`, `PurchaseReportFilterData`, `PurchaseReportValidator`, and the filter hash used by snapshots.
- [x] 2.4 Reset or normalize unsupported sort fields when switching between `detail` and `header` modes.
- [x] 2.5 Add Livewire tests for default mode, invalid mode fallback, mode persistence, and unapplied mode changes being excluded from export snapshots.

## 3. Header Mode Query And Mapping

- [x] 3.1 Extend the purchase report query layer with a header-mode query rooted in `Modules\Purchase\Entities\Purchase`.
- [x] 3.2 Reuse the existing date basis, supplier, tag, document status, payment status, global/non-global scope, and active-payment aggregate semantics in header mode.
- [x] 3.3 Add a header-mode row mapper that returns the concise header columns: `Tanggal`, `Nomor Transaksi`, `Nomor Pembelian Supplier`, `Nama Panggilan`, `Status Dokumen`, `Status Pembayaran`, `Memo`, `Total`, `Sisa Tagihan`, `Tanggal Jatuh Tempo`, `Jumlah Kena Pajak`, `Total Pajak`, `Pembayaran`, `No Ref`, and `Tag`.
- [x] 3.4 Add supported header-mode sorting for header columns and stable tie-breaker ordering.
- [x] 3.5 Add tests proving a purchase with multiple detail rows appears once in header mode and multiple times in detail mode.
- [x] 3.6 Add tests proving header mode payment status, payment amount, and due amount are derived from active purchase payments.

## 4. Report UI

- [x] 4.1 Add a `Mode Laporan` control to `resources/views/livewire/reports/purchase-report.blade.php` with `Detail` and `Header` options.
- [x] 4.2 Keep the existing detail-mode table and column contract unchanged.
- [x] 4.3 Render a concise header-mode table when `reportMode` is `header`.
- [x] 4.4 Ensure pagination, sorting, empty states, loading states, and purchase reference links work in both modes.
- [x] 4.5 Add view/Livewire assertions that header mode omits product-line columns and detail mode still includes them.

## 5. Mode-Aware Export

- [x] 5.1 Update `PurchaseReportExport` or introduce a mode-aware export mapping so headings and rows match the selected report mode.
- [x] 5.2 Ensure Excel and CSV exports use the last successfully applied report mode and filters, not pending mode/filter changes.
- [x] 5.3 Ensure header-mode exports contain only concise header columns and exclude product-line columns.
- [x] 5.4 Preserve detail-mode export headings, raw numeric values, missing-value behavior, filename format, Excel metadata rows, CSV header-first behavior, and PDF-unavailable behavior.
- [x] 5.5 Add export parity tests for detail mode and header mode, including sort order and full-result export beyond current pagination.

## 6. Verification

- [x] 6.1 Run focused purchase index due-date tests.
- [x] 6.2 Run focused purchase report mode/filter/export tests.
- [x] 6.3 Run existing purchase report hardening/export/performance tests to catch regressions.
- [x] 6.4 Run a broader `php artisan test` or `composer test:fresh-sqlite` pass if time and environment permit.
- [ ] 6.5 Manually verify `/purchases` and `/reports/purchase-report` in a browser if implementation changes affect layout.

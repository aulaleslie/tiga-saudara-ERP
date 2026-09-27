## Why

Purchase users need the normal `/purchases` list to show invoice due dates directly so overdue and upcoming supplier obligations are visible without opening each purchase. The existing `Daftar Pembelian` report is detail-line oriented, but users also need a concise invoice-header view from the same report screen without losing the existing detail mode.

## What Changes

- Add `Tanggal Jatuh Tempo` as a visible, sortable column on the normal `/purchases` purchase list.
- Add a `Mode Laporan` control to `Laporan Daftar Pembelian` with `Detail` and `Header` modes.
- Keep `Detail` mode as the default and preserve the existing purchase-detail/product-line report behavior.
- Add `Header` mode that renders one concise row per purchase invoice using header-level columns.
- Make Excel and CSV exports follow the selected report mode and column contract.
- Persist the selected report mode through query string and/or session state so pagination, sorting, refresh, and export stay aligned.
- No database schema changes, route changes, or permission changes.

## Capabilities

### New Capabilities
- `purchase-index-list`: Defines operational `/purchases` list visibility and sorting for purchase due dates.

### Modified Capabilities
- `purchase-list-report`: Adds report mode selection, header-mode result rows, header-mode column/export contract, and persisted mode behavior to the existing `Daftar Pembelian` report.

## Impact

- Affects `app/Livewire/Purchase/PurchaseTable.php` and `resources/views/livewire/purchase/purchase-table.blade.php`.
- Affects `App\Livewire\Reports\PurchaseReport`, purchase report filter/query/snapshot/export services, and `resources/views/livewire/reports/purchase-report.blade.php`.
- Affects purchase report feature tests and purchase list Livewire/table tests.
- Reuses existing `purchases.due_date`, report permissions, report route, Livewire 3, Laravel/Eloquent, and Excel/CSV export infrastructure.

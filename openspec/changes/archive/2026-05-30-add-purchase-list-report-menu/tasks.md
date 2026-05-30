## 1. Baseline And Test Coverage

- [x] 1.1 Review current purchase report behavior in `app/Livewire/Reports/PurchaseReport.php`, `resources/views/livewire/reports/purchase-report.blade.php`, and `Modules/Reports/Resources/views/purchase-report/index.blade.php`.
- [x] 1.2 Review current purchase sidebar/report menu behavior in `resources/views/layouts/menu.blade.php`.
- [x] 1.3 Add or update feature coverage proving users with `purchaseReports.access` see `Laporan -> Pembelian -> Daftar Pembelian` and users without it do not.
- [x] 1.4 Add or update feature coverage proving `/reports/purchase-report` renders page title and breadcrumb as `Daftar Pembelian`.
- [x] 1.5 Add or update Livewire coverage proving the default `startDate` and `endDate` are both today.
- [x] 1.6 Add or update Livewire coverage proving period presets update pending dates but do not refresh results until `Filter` is clicked.
- [x] 1.7 Add or update Livewire/query coverage for date basis filtering by transaction date and due date.
- [x] 1.8 Add or update Livewire/query coverage for supplier multi-select filtering with any selected supplier.
- [x] 1.9 Add or update Livewire/query coverage for delivery status and payment status filters.
- [x] 1.10 Add or update view coverage proving purchases with multiple details render as one report row.
- [x] 1.11 Add or update view coverage proving the `Ekspor` dropdown shell is visible with disabled non-functional options.

## 2. Report State And Query Contract

- [x] 2.1 Change `PurchaseReport::mount()` so non-global purchase list reports default `startDate` and `endDate` to today.
- [x] 2.2 Add Livewire state for period preset selection without automatically setting `filterTriggered` or refreshing results.
- [x] 2.3 Add Livewire action logic to apply a selected period preset to pending start and end dates.
- [x] 2.4 Add Livewire state for `dateBasis` with supported values for transaction date and due date.
- [x] 2.5 Add Livewire state for `transactionType`, defaulting to purchase invoice behavior for v1.
- [x] 2.6 Rename or add filter state for delivery status separately from payment status while preserving existing payment status semantics.
- [x] 2.7 Extend `PurchaseReportFilterData` to carry `dateBasis`, `transactionType`, and delivery status fields.
- [x] 2.8 Extend `PurchaseReportValidator` to validate date basis, transaction type, delivery status, and existing payment status values.
- [x] 2.9 Extend `PurchaseReportQueryService` so date filtering uses `date` or `due_date` based on `dateBasis`.
- [x] 2.10 Extend `PurchaseReportQueryService` so delivery status filters use canonical `purchases.status` values.
- [x] 2.11 Ensure supplier multi-select continues to use `whereIn` and excludes unrelated suppliers.

## 3. Report Page Layout

- [x] 3.1 Update `Modules/Reports/Resources/views/purchase-report/index.blade.php` title and breadcrumb to `Daftar Pembelian`.
- [x] 3.2 Rework `resources/views/livewire/reports/purchase-report.blade.php` to show a top filter bar with start date, end date, period preset, `Filter`, `Filter lainnya`, and `Ekspor`.
- [x] 3.3 Move transaction type, date basis, supplier, delivery status, and payment status controls into a right-side `Filter lainnya` drawer.
- [x] 3.4 Add drawer controls for `Reset filter`, `Batalkan`, and `Filter` using existing CoreUI/Bootstrap conventions.
- [x] 3.5 Wire `Batalkan` so it closes the drawer without applying unsubmitted changes to displayed results.
- [x] 3.6 Wire drawer `Filter` and top-bar `Filter` so both submit the current pending filter state intentionally.
- [x] 3.7 Render delivery status labels as `Draft`, `Menunggu Persetujuan`, `Disetujui`, `Ditolak`, `Diterima Sebagian`, `Diterima`, `Diretur Sebagian`, and `Diretur`.
- [x] 3.8 Render the `Ekspor` dropdown shell with disabled options and no file generation action.
- [x] 3.9 Keep the result table at one row per purchase header and avoid adding product/detail-line columns in v1.

## 4. Navigation And Authorization

- [x] 4.1 Replace the flat purchase report sidebar item with nested `Laporan -> Pembelian -> Daftar Pembelian` navigation in `resources/views/layouts/menu.blade.php`.
- [x] 4.2 Keep menu visibility gated by `purchaseReports.access`.
- [x] 4.3 Keep the nested menu item linked to `reports.purchase-report.index`.
- [x] 4.4 Ensure existing global purchase report menu behavior remains unchanged unless the implementation explicitly needs to group it separately.
- [x] 4.5 Verify route middleware still uses `can:purchaseReports.access` for `/reports/purchase-report`.

## 5. Cleanup And Verification

- [x] 5.1 Remove or hide functional export buttons from the new UI while preserving backend export code for future use.
- [x] 5.2 Run focused purchase report tests with `php artisan test --filter=PurchaseReport`.
- [x] 5.3 Run focused report module tests as needed for menu/page changes.
- [x] 5.4 Manually verify `/reports/purchase-report` as an authorized user: nested menu, today default dates, drawer behavior, filters, disabled export shell, and one-row-per-purchase output.
- [x] 5.5 Manually verify an unauthorized user cannot see or access the purchase list report.

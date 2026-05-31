## 1. Extend FilterData with hash/serialization support

- [x] 1.1 Add `toArray()` method to `PurchaseBySupplierReportFilterData` returning all filter fields as an array
- [x] 1.2 Add `fromArray(array $data): self` static constructor to `PurchaseBySupplierReportFilterData`
- [x] 1.3 Add `hash(): string` method to `PurchaseBySupplierReportFilterData` returning `md5(serialize($this->toArray()))`

## 2. Create snapshot DTO and service

- [x] 2.1 Create `app/Services/Reports/PurchaseBySupplierReportSnapshot.php` with fields: `snapshotKey`, `validatedFilterHash`, `generatedAt`, `actorUserId`, `scopeSettingId`, `resultCount`; include `fromArray()` and `toArray()`
- [x] 2.2 Create `app/Services/Reports/PurchaseBySupplierReportSnapshotService.php` with `createSnapshot()`, `getLatestSnapshot()`, `isValidForExport()`, `invalidate()`, and `persist()` using session key `purchase_by_supplier_report_snapshot`

## 3. Add export row mapper to query service

- [x] 3.1 Add `mapRowForExport(PurchaseDetail $detail, float $runningTotal): array` static method to `PurchaseBySupplierReportQueryService` returning 11 columns: `Supplier`, `Tanggal`, `Tipe transaksi`, `No. transaksi`, `Nama produk`, `Keterangan`, `Qty`, `Unit`, `Harga per unit`, `Nominal tagihan`, `Total nominal tagihan`

## 4. Create export class

- [x] 4.1 Create `app/Exports/PurchaseBySupplierReportExport.php` implementing `FromQuery`, `WithHeadings`, `WithMapping`, `WithEvents`
- [x] 4.2 Implement constructor accepting `Builder $query`, `PurchaseBySupplierReportFilterData $filterData`, `bool $isCsv = false`
- [x] 4.3 Implement `query()` returning the injected query builder
- [x] 4.4 Implement `headings()` returning keys from `mapRowForExport()` (11 column headers)
- [x] 4.5 Implement `map($row)` with stateful `$runningTotals` array accumulating `sub_total` per `supplier_id`, calling `mapRowForExport()` with the accumulated total
- [x] 4.6 Implement `registerEvents()` returning `[]` when `$isCsv` is true; otherwise return `AfterSheet` event that inserts 2 rows above headers, sets title (`LAPORAN PEMBELIAN PER SUPPLIER`) in row 1 merged across all columns, sets period text in row 2, and bolds the header row (row 3)

## 5. Integrate snapshot guard and export methods into Livewire component

- [x] 5.1 Add `PurchaseBySupplierReportSnapshotService` injection to `applyFilters()` — create snapshot after successful validation, passing result count from `$query->count()`
- [x] 5.2 Add `exportExcel(PurchaseBySupplierReportSnapshotService $snapshotService, PurchaseBySupplierReportQueryService $queryService)` method to `PurchaseBySupplierReport` Livewire component
- [x] 5.3 In `exportExcel()`: guard for `!$this->filterTriggered` and invalid snapshot, build filter from `appliedFilters`, apply sort, return `Excel::download()` with `.xlsx` filename `purchases_by_vendor_{start}_{end}.xlsx`
- [x] 5.4 Add `exportCsv(PurchaseBySupplierReportSnapshotService $snapshotService, PurchaseBySupplierReportQueryService $queryService)` method with same guard and same query, return `Excel::download()` with `Excel::CSV` and `.csv` filename `purchases_by_vendor_{start}_{end}.csv`

## 6. Add export dropdown to blade view

- [x] 6.1 Add export dropdown button group to `resources/views/livewire/reports/purchase-by-supplier-report.blade.php` with `Excel` (`wire:click="exportExcel"`) and `CSV` (`wire:click="exportCsv"`) items, styled to match the `purchase-report` blade pattern

## 7. Update and add tests

- [x] 7.1 Replace `it_does_not_have_export_buttons` in `PurchaseBySupplierReportTest` with `it_has_export_buttons` that asserts the page contains `Excel` and `CSV`
- [x] 7.2 Add test: `it_blocks_export_excel_before_filter_is_applied` — assert `exportExcel` dispatches an error alert when `filterTriggered` is false
- [x] 7.3 Add test: `it_blocks_export_csv_before_filter_is_applied` — assert `exportCsv` dispatches an error alert when `filterTriggered` is false
- [x] 7.4 Add test: `it_blocks_export_when_snapshot_is_stale` — apply filters, change a filter, assert export is blocked with alert
- [x] 7.5 Add test: `it_downloads_xlsx_after_applying_filters` — apply filters, call `exportExcel`, assert response is a download with correct filename
- [x] 7.6 Add test: `it_downloads_csv_after_applying_filters` — apply filters, call `exportCsv`, assert response is a download with correct filename
- [x] 7.7 Add test: `it_exports_same_row_count_as_filtered_display` — create N purchase details, apply filters, assert `exportExcel` produces N rows (using `Excel::fake()` and `assertDownloaded`)
- [x] 7.8 Add test: `it_export_respects_supplier_filter` — create details for two suppliers, filter to one, assert export only contains rows for the selected supplier

## 8. Run tests and verify

- [x] 8.1 Run `php artisan test --filter PurchaseBySupplierReport` and confirm all tests pass
- [x] 8.2 Run `php artisan test --filter PurchaseReport` to confirm no regressions on the existing purchase report

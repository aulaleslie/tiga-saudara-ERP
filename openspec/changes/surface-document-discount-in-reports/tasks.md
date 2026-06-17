## 1. Per Customer report — document discount row

- [ ] 1.1 In `app/Services/Reports/SaleByCustomerReportQueryService.php`, add a helper that builds the synthetic `Diskon` row from `Sale.discount_amount` (negative `Nominal tagihan`, `Nama produk` = `Diskon`, qty/unit blank) and reduces the running total.
- [ ] 1.2 Track the current invoice in `app/Livewire/Reports/SaleByCustomerReport.php` (and `SaleByCustomerReportSnapshotService.php`) so the `Diskon` row is emitted once per invoice — after the invoice's product/DPP rows and before its `Pajak` row — never per detail line.
- [ ] 1.3 Ensure `mapRows()` and `mapRowsForExport()` both produce the discount row identically, folded into the same running-`Total nominal tagihan` accumulator used by the tax row, including across pagination.
- [ ] 1.4 Update `app/Exports/SaleByCustomerReportExport.php` to carry the discount row in parity with the on-screen report.
- [ ] 1.5 Emit no `Diskon` row when `discount_amount` is 0.

## 2. Per Supplier report — document discount row

- [ ] 2.1 Mirror task 1.1 in `app/Services/Reports/PurchaseBySupplierReportQueryService.php` using `Purchase.discount_amount`.
- [ ] 2.2 Track the current invoice in `app/Livewire/Reports/PurchaseBySupplierReport.php` (and `PurchaseBySupplierReportSnapshotService.php`) to emit one `Diskon` row per purchase within the supplier group.
- [ ] 2.3 Apply the discount row to both screen and export mapping with the shared running-total accumulator.
- [ ] 2.4 Update `app/Exports/PurchaseBySupplierReportExport.php` to match.
- [ ] 2.5 Emit no `Diskon` row when `discount_amount` is 0.

## 3. Daftar Penjualan — discount column relabel

- [x] 3.1 In `app/Services/Reports/SaleReportQueryService.php`, point the displayed `Diskon` column at the document discount (`sale.discount_amount`) and retain the derived `Diskon %`; remove the always-zero per-line `Diskon` and `Diskon Per Baris %` columns from both detail and header modes.
- [x] 3.2 Update `headingsFor()` and the row maps so detail, header, and global variants are consistent.
- [x] 3.3 Update `app/Exports/SaleReportExport.php` heading/column map to match the on-screen columns.

## 4. Daftar Pembelian — discount column relabel

- [x] 4.1 Apply the same column relabel/prune in `app/Services/Reports/PurchaseReportQueryService.php` using `purchase.discount_amount`.
- [x] 4.2 Update its `headingsFor()` and row maps for detail, header, and global variants.
- [x] 4.3 Update `app/Exports/PurchaseReportExport.php` heading/column map to match.

## 5. Tests

- [x] 5.1 Add a `SaleByCustomerReportTest` case: discounted single-line invoice expands to product/DPP -> Diskon (negative) -> Pajak, and the post-tax running total equals the sale total.
- [x] 5.2 Add a `SaleByCustomerReportTest` case: multi-line discounted invoice emits exactly one Diskon row; undiscounted invoice emits none.
- [x] 5.3 Add matching `PurchaseBySupplierReportTest` cases (single-line, multi-line, zero-discount) within a supplier group.
- [x] 5.4 Extend `SaleReportExportParityTest` and `PurchaseReportExportParityTest` to assert the new discount columns/heads match between screen and export for a discounted document.
- [x] 5.5 Extend `SaleByCustomerReportExport`/`PurchaseBySupplierReportExport` coverage to assert the exported discount row matches the on-screen row position and value.
- [x] 5.6 Update `SaleReportHardeningTest` / `PurchaseReportHardeningTest` expectations affected by the column relabel.
- [x] 5.7 Run `composer test:fresh-sqlite` (or focused `php artisan test --filter` on the report tests) and confirm green.

## Why

The supplier purchase and customer sales reports currently hide persisted line tax amounts inside transaction totals, while accounting users need the reports to show tax as separate `Pajak` rows matching the provided with-tax reference sample. Exports also carry the `Keterangan` column even though the requested spreadsheet/CSV output should omit it.

## What Changes

- Add tax-aware row expansion to `Pembelian Per Supplier`: each persisted purchase detail still produces its product row, and any detail with `product_tax_amount > 0` also produces a following `Pajak` row.
- Add the same tax-aware row expansion to `Penjualan Per Customer` using persisted sale detail tax amounts.
- Treat persisted `product_tax_amount` as the source of truth for whether a tax row appears, independent of current `settings.is_pkp` or current tax settings.
- Update report running totals, supplier/customer subtotals, and grand totals to include both product rows and tax rows.
- Keep UI pagination based on real purchase/sale detail rows so existing pagination behavior remains stable, even though rendered table rows may increase when tax rows are present.
- Remove `Keterangan` from Excel and CSV exports for these party-grouped reports while leaving the on-screen `Keterangan` column unchanged.

## Capabilities

### New Capabilities
<!-- None. This change modifies existing report capabilities. -->

### Modified Capabilities
- `purchase-by-supplier-report`: Display persisted purchase tax amounts as separate `Pajak` rows and include those rows in on-screen running totals while preserving existing detail-row pagination.
- `purchase-by-supplier-report-export`: Export purchase tax rows and remove the `Keterangan` column from XLSX/CSV output.
- `sales-by-customer-report`: Display and export persisted sale tax amounts as separate `Pajak` rows, include those rows in running totals, and remove the `Keterangan` column from XLSX/CSV output.

## Impact

- Affected Livewire components: `App\Livewire\Reports\PurchaseBySupplierReport`, `App\Livewire\Reports\SaleByCustomerReport`.
- Affected query/mapping services: `PurchaseBySupplierReportQueryService`, `SaleByCustomerReportQueryService`.
- Affected export classes: `PurchaseBySupplierReportExport`, `SaleByCustomerReportExport`.
- Affected Blade views: `resources/views/livewire/reports/purchase-by-supplier-report.blade.php`, `resources/views/livewire/reports/sale-by-customer-report.blade.php`.
- Affected tests: report feature/export tests for purchase-by-supplier and sales-by-customer behavior.
- No database schema changes, no new permissions, and no changes to transaction persistence.

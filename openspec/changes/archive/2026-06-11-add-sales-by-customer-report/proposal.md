## Why

Purchases have a "Pembelian Per Supplier" report (`PurchaseBySupplierReport`) that groups purchase lines by supplier with category filters, tag/category AND-OR logic, and running per-supplier subtotals. Sales have no equivalent — there is no way to view sales lines grouped per customer with the same analytical filters. This adds the missing mirror so the sales reporting menu matches purchases.

## What Changes

- Add a new `SaleByCustomerReport` Livewire component mirroring `PurchaseBySupplierReport`:
  - Date range + period presets.
  - Multi-select searchable customer, tag, and product-category filters with display pills.
  - Tag and category match logic ("Salah satu" / "Semua").
  - Running subtotal per customer across paginated detail rows, including carry-over from previous pages.
  - Snapshot-validated Excel/CSV export.
- Add the supporting service stack mirroring the purchase one: `SaleByCustomerReportFilterData`, `SaleByCustomerReportQueryService`, `SaleByCustomerReportValidator`, `SaleByCustomerReportSnapshot`, `SaleByCustomerReportSnapshotService`, and `SaleByCustomerReportExport`.
- Add a controller, a new route `reports.sale-by-customer.index`, and restructure the sales reports menu into a "Penjualan" dropdown (Daftar Penjualan / Penjualan Per Customer / Laporan Penjualan Global) to match the purchase "Pembelian" dropdown.
- **NON-OBVIOUS**: group/sort by `customer_id` (column `customer_name`), and join sale detail lines (`sale_details.sub_total`) rather than purchase detail lines. Scope to current `setting_id`.

## Capabilities

### New Capabilities
- `sales-by-customer-report`: A per-customer sales report (Penjualan Per Customer) with customer/tag/category filters, AND-OR match logic, running per-customer subtotals, and snapshot-validated exports, reached from a restructured sales reports menu.

### Modified Capabilities
<!-- None: no existing spec governs the sales reports menu structure. -->

## Impact

- New: `app/Livewire/Reports/SaleByCustomerReport.php`; services under `app/Services/Reports/SaleByCustomerReport*.php`; `app/Exports/SaleByCustomerReportExport.php`; `resources/views/livewire/reports/sale-by-customer-report.blade.php`; `Modules/Reports/Http/Controllers/SaleByCustomerReportController.php`; `Modules/Reports/Resources/views/sale-by-customer/index.blade.php`.
- Modified: `Modules/Reports/Routes/web.php` (new `reports.sale-by-customer.index` route, gated `can:saleReports.access`); `resources/views/layouts/menu.blade.php` (sales reports become a nested dropdown matching purchases).
- Reads: `Modules\Sale\Entities\Sale` + `sale_details`, `Modules\People\Entities\Customer` (`customer_name`), `Modules\Product\Entities\Category`, `Spatie\Tags\Tag`.
- Permission: reuses `saleReports.access` (single permission for all sales report surfaces, matching how purchases reuse `purchaseReports.access`); no new permission.
- Tests: new `SaleByCustomerReportTest` mirroring `PurchaseBySupplierReportTest`, including running-subtotal carry-over across pages.

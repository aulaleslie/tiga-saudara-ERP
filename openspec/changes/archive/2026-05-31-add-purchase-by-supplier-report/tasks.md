## 1. Contract and Test Setup

- [x] 1.1 Add feature tests proving users with `purchaseReports.access` can see/open `Laporan -> Pembelian -> Pembelian Per Supplier` and users without it cannot.
- [x] 1.2 Add Livewire/report tests for default current-month `Tanggal awal` and `Tanggal akhir`.
- [x] 1.3 Add query/service tests for one purchase with multiple details rendering one row per detail under the supplier.
- [x] 1.4 Add query/service tests proving suppliers without matching purchase details are hidden.
- [x] 1.5 Add query/service tests for `Nominal tagihan` using `purchase_details.sub_total` and `Total nominal tagihan` as a supplier running total.

## 2. Report Infrastructure

- [x] 2.1 Add `PurchaseBySupplierReportFilterData` with date range, supplier IDs, tag IDs, tag logic, category IDs, category logic, sort field, sort direction, setting scope, and period state.
- [x] 2.2 Add `PurchaseBySupplierReportValidator` with Bahasa Indonesia validation messages and valid values for tag/category logic and sorting.
- [x] 2.3 Add `PurchaseBySupplierReportQueryService` rooted in `PurchaseDetail`, eager-loading/joining purchase, supplier, product, product unit, category, and tags.
- [x] 2.4 Implement active-setting scoping and date-range filtering on related purchase date.
- [x] 2.5 Implement supplier OR filtering.
- [x] 2.6 Implement tag `Salah satu` and `Mencakup semua` filtering.
- [x] 2.7 Implement category `Salah satu` and literal `Mencakup semua` filtering.
- [x] 2.8 Implement supplier-name sorting and supplier-total sorting with transaction-date-desc ordering inside each supplier group.
- [x] 2.9 Implement row mapping for the required columns: `Supplier / Tanggal`, `Tipe transaksi`, `No. transaksi`, `Nama produk`, `Keterangan`, `Qty`, `Unit`, `Harga per unit`, `Nominal tagihan`, and `Total nominal tagihan`.

## 3. Livewire Component

- [x] 3.1 Add `App\Livewire\Reports\PurchaseBySupplierReport` using Livewire pagination and explicit filter application.
- [x] 3.2 Initialize default filters to the current calendar month.
- [x] 3.3 Add period preset handling that updates pending dates without refreshing rows until `Filter` is applied.
- [x] 3.4 Add server-side searchable multi-select state for suppliers, tags, and product categories without preloading full datasets.
- [x] 3.5 Add selected-value pill state and removal actions for supplier, tag, and category filters.
- [x] 3.6 Add tag/category logic state for `Mencakup semua` and `Salah satu`.
- [x] 3.7 Group paginated rows by supplier for rendering while preserving normal row pagination.

## 4. Routing, Page, and Menu

- [x] 4.1 Add a Reports module route for the new report, gated by `can:purchaseReports.access`.
- [x] 4.2 Add a controller action or route target returning the `Pembelian Per Supplier` report page.
- [x] 4.3 Add the Blade page shell with title and breadcrumb `Pembelian Per Supplier`.
- [x] 4.4 Add the Livewire Blade view with top filters, `Filter lainnya` drawer, table headers, supplier group labels, row values, empty state, and pagination.
- [x] 4.5 Add the sidebar menu item under `Laporan -> Pembelian` without changing existing `Daftar Pembelian` behavior.
- [x] 4.6 Ensure no active Excel, CSV, or PDF export action is available for this report.

## 5. Verification

- [x] 5.1 Run all tests in `PurchaseBySupplierReportTest.php` and ensure they pass.
- [x] 5.2 Serve the application and login as admin/authorized user.
- [x] 5.3 Navigate to `Laporan -> Pembelian -> Pembelian Per Supplier`.
- [x] 5.4 Ensure filter behaviors work correctly and data renders correctly according to spec.

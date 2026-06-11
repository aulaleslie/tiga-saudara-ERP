## Context

`app/Livewire/Reports/PurchaseBySupplierReport.php` groups purchase detail lines by supplier with category filters, tag/category AND-OR logic, snapshot-validated export, and running per-supplier subtotals (including carry-over computed from a windowed pre-query when `currentPage > 1`). It is backed by `PurchaseBySupplierReport{FilterData,QueryService,Validator,Snapshot,SnapshotService}` and `PurchaseBySupplierReportExport`, with a controller, the `reports.purchase-by-supplier.index` route, and a "Pembelian" menu dropdown. No sales equivalent exists. We mirror the whole stack for customers.

## Goals / Non-Goals

**Goals:**
- A `SaleByCustomerReport` Livewire component and full service stack mirroring the purchase-by-supplier one, querying sales.
- New controller, `reports.sale-by-customer.index` route gated by `saleReports.access`, and a restructured sales reports menu dropdown.

**Non-Goals:**
- No global variant (the purchase-by-supplier report has none; match that).
- No new permission — all three sales report surfaces share `saleReports.access`, matching how the purchase by-supplier report reuses `purchaseReports.access`.
- No changes to the Daftar Penjualan report internals (that is `rebuild-sales-list-report`).

## Decisions

- **Mirror the purchase-by-supplier stack 1:1 in shape.** Port FilterData/QueryService/Validator/Snapshot/SnapshotService/Export and the component, swapping supplier→customer, `purchases`/`purchase_details`→`sales`/`sale_details`, and `supplier_name`→`customer_name`. Category and tag logic ("Salah satu"/"Semua") carry over unchanged.
- **Preserve the running-subtotal carry-over algorithm.** Keep the windowed pre-query that sums prior pages per customer so subtotals continue across pagination, since it is the report's defining behavior. Group/accumulate by `customer_id`.
- **Single shared permission.** Gate the route with `can:saleReports.access`. This matches the purchase pattern (by-supplier reuses `purchaseReports.access`) and the user's explicit decision that sales report surfaces share one permission.
- **Restructure the menu in this change.** Today sales reports are two flat links ("Laporan Penjualan", "Laporan Penjualan Global"). Adding a third surface is the natural point to wrap them in a "Penjualan" dropdown matching the "Pembelian" dropdown. Alternative — leaving flat links — was rejected as it would not match the purchase UX the user asked to replicate.

## Risks / Trade-offs

- [Carry-over subtotal off-by-one across pages] → Mitigated by a test mirroring the purchase by-supplier test that asserts continuity across a page boundary.
- [Grouping/join uses wrong detail table or key] → Use `sale_details.sub_total` and `sales.customer_id`; asserted by grouping tests.
- [Menu restructure regressing existing report links] → Keep route names/permissions identical; only nest the existing links and add the new one; verify active-state highlighting for all three.
- [Category filter scope] → Categories are filtered by `setting_id` like the purchase report's category search.

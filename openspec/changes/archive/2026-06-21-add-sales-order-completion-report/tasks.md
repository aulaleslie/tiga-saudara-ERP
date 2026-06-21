## 1. Report Foundations

- [x] 1.1 Add `SalesOrderCompletionReportFilterData`, validator, snapshot, and snapshot service classes under `app/Services/Reports`, following the existing report snapshot pattern.
- [x] 1.2 Add `SalesOrderCompletionReportQueryService` that builds one row per selected Sale, scopes by active `setting_id`, and supports `Penawaran` and `Pemesanan` source-stage filtering.
- [x] 1.3 Implement payment amount derivation in the query service using active `sale_payments` first, then `sales.paid_amount`, then `sales.total_amount - sales.due_amount`.
- [x] 1.4 Implement delivery amount derivation from approved dispatch details using the existing dispatch composite key and commercial amount approach from `SaleDeliveryReportQueryService`.
- [x] 1.5 Implement invoice amount and order status mapping rules for draft, waiting approval, approved, dispatched, and returned Sales.

## 2. Report UI And Routing

- [x] 2.1 Add a `SalesOrderCompletionReportController` and route under `Modules/Reports/Routes/web.php`, gated by `saleReports.access`.
- [x] 2.2 Add the Reports module wrapper view under `Modules/Reports/Resources/views/sales-order-completion/index.blade.php`.
- [x] 2.3 Add the `app/Livewire/Reports/SalesOrderCompletionReport.php` component with date range, period preset, `Mulai dari`, customer, tag, and tag logic filters.
- [x] 2.4 Add the Livewire Blade view with the report title, IDR currency label, filter controls/drawer, empty/loading states, summary table, and totals.
- [x] 2.5 Update `ReportsController` so the `Penyelesaian pesanan penjualan` card links to the new route and no longer renders as a placeholder.
- [x] 2.6 Ensure unauthorized users cannot access the route and cannot see the actionable card unless they have `saleReports.access`.

## 3. Export Implementation

- [x] 3.1 Add `SalesOrderCompletionReportExport` supporting XLSX and CSV from the applied filter snapshot.
- [x] 3.2 Ensure CSV exports start with the table header row and contain one row per exported Sale without metadata or total rows.
- [x] 3.3 Ensure XLSX exports include company name, `sales_order_completion`, selected date range, `(dalam IDR)`, table rows, and a final `Total` row.
- [x] 3.4 Block exports before filters are applied and after pending filters drift from the last applied snapshot.

## 4. Feature Coverage

- [x] 4.1 Add access and landing navigation tests proving the card is actionable for `saleReports.access` users and hidden/denied otherwise.
- [x] 4.2 Add source-stage tests proving `Penawaran` includes only `DRAFTED` Sales and `Pemesanan` includes `WAITING_APPROVAL`, `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, `RETURNED PARTIALLY`, and `RETURNED`.
- [x] 4.3 Add filter tests for date range, customer filter, tag `Salah satu`, and tag `Mencakup semua`.
- [x] 4.4 Add amount tests for zero delivery, approved dispatch delivery, draft/waiting-approval zero invoice amount, approved-or-later invoice amount, active payment rows, invalidated payment exclusion, and header payment fallback.
- [x] 4.5 Add order status mapping tests for `Belum Dibayar`, `Terbayar Sebagian`, and `Selesai`.
- [x] 4.6 Add tenant scoping tests proving Sales from other settings are excluded.
- [x] 4.7 Add export tests for CSV plain-table shape, XLSX metadata/total rows, and stale-export blocking.

## 5. Verification

- [x] 5.1 Review the report output with realistic test data in the browser to confirm UI layout and mapping.
- [x] 5.2 Ensure all filtering criteria properly constrain the returned paginated sets.
- [x] 5.3 Execute exports manually to verify shape matches the OpenSpec requirements exactly.

## 1. Filter DTO and validation

- [x] 1.1 Create `app/Services/Reports/CustomerReceivablesReportFilterData.php` with fields: `endDate` (as-of), `scopeSettingId`, `dueDateUntil`, `customerIds`, `tagIds`, `tagLogic`, `sortField` (`customer_name` | `total_balance`), `sortDirection`, `periodPreset`; plus `toArray`/`fromArray` and a `hash()` for export gating (mirror `SaleByCustomerReportFilterData`).
- [x] 1.2 Create `app/Services/Reports/CustomerReceivablesReportValidator.php` validating date format, allowed sort fields/directions, and tag logic values; default missing values sensibly.

## 2. Query service (as-of balance + grouping + sort)

- [x] 2.1 Create `app/Services/Reports/CustomerReceivablesReportQueryService.php` building a `Sale` query scoped to `setting_id`, `sales.date <= endDate`, joined to `customers`, with an `active` per-`sale_id` payment-sum subquery (`leftJoinSub`, `status='ACTIVE' AND sale_payments.date <= endDate`).
- [x] 2.2 Compute `sisa_piutang = round(total_amount - COALESCE(paid_to_date,0), 2)` and filter to `> 0`; round to 2 decimals to absorb float drift.
- [x] 2.3 Apply optional filters: `dueDateUntil` (`due_date <= x`), `customerIds` (`whereIn`), and tag grouping via `whereHas('tags')` with all/any logic (copy the pattern from `SaleByCustomerReportQueryService`).
- [x] 2.4 Implement `applySort` for `customer_name` and `total_balance` using a per-customer aggregate subquery so customers do not interleave; tie-break on `customers.id`.
- [x] 2.5 Add a `mapRows` helper producing the on-screen columns (Pelanggan/Tanggal, Transaksi="Sales Invoice"/"Faktur Penjualan", No.=`reference`, Jatuh Tempo=`due_date`, Deskripsi=`note`, Jumlah=`total_amount`, Sisa Piutang=computed) and a `mapRowsForExport` variant with flattened Pelanggan/Tanggal columns.

## 3. Snapshot / export parity

- [x] 3.1 Create `app/Services/Reports/CustomerReceivablesReportSnapshot.php` (snapshotKey, validatedFilterHash, generatedAt, actorUserId, scopeSettingId, resultCount) with `toArray`/`fromArray`.
- [x] 3.2 Create `app/Services/Reports/CustomerReceivablesReportSnapshotService.php` with session persist/get/`isValidForExport`/`invalidate` (mirror the Sale by Customer service; distinct session key).

## 4. Livewire component and views

- [x] 4.1 Create `app/Livewire/Reports/CustomerReceivablesReport.php` wiring filter state, period presets, customer/tag selectors, sort controls, generate action (creates snapshot), and grouped result with per-customer subtotals.
- [x] 4.2 Create the Livewire blade view rendering grouped rows, collapsible customer headers, subtotal rows, and the filter modal (as-of date, due-date-until, customers, tags + all/any logic, sort column + direction).
- [x] 4.3 Create `Modules/Reports/Resources/views/customer-receivables/index.blade.php` hosting the Livewire component.

## 5. Controller, route, and landing wiring

- [x] 5.1 Create `Modules/Reports/Http/Controllers/CustomerReceivablesReportController.php@index` returning the view.
- [x] 5.2 Add the route in `Modules/Reports/Routes/web.php` under the `reports` prefix, named `reports.customer-receivables.index`, gated `can:saleReports.access`.
- [x] 5.3 In `Modules/Reports/Http/Controllers/ReportsController.php`, replace the `Piutang pelanggan` placeholder card with a real `route => 'reports.customer-receivables.index'` (remove `is_placeholder`).

## 6. Exports

- [x] 6.1 Implement CSV export gated by the snapshot hash, matching the on-screen result and the sample CSV column order/format.
- [x] 6.2 Implement XLSX export with the same rows.
- [x] 6.3 Implement PDF export (reuse the report PDF view pattern used by the report family).

## 7. Tests

- [x] 7.1 Feature test: as-of balance replay — back-dated cutoff excludes later payments; invalidated payments excluded; as-of=today equals current balance.
- [x] 7.2 Feature test: only invoices with remaining > 0 appear; tenant scoping; grouping and per-customer subtotals correct.
- [x] 7.3 Feature test: filters (due-date-until, customer, tag all/any) and both sort modes (no interleaving).
- [x] 7.4 Feature test: access control (403 without `saleReports.access`; landing card visibility) and export parity (export matches on-screen; stale snapshot blocked).

## 8. Verification

- [x] 8.1 Run `composer test:fresh-sqlite` (or focused `php artisan test --filter=CustomerReceivables`) and confirm green.
- [x] 8.2 Manually verify the report against `report-sample/piutang` (same as-of date reproduces sample grouping, subtotals, and outstanding set).

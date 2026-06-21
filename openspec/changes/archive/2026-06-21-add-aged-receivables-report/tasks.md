## 1. Report Foundations

- [x] 1.1 Add aged receivables filter data and snapshot service classes under `app/Services/Reports`, following the existing report snapshot pattern.
- [x] 1.2 Add an aged receivables query service that computes as-of outstanding balances from sales minus active sale payments up to the as-of date.
- [x] 1.3 Implement transaction-date bucket aggregation for `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari`, including two-decimal rounding and zero-total exclusion.
- [x] 1.4 Add customer and tag filtering plus customer-name and total-balance sorting to the query service.

## 2. Route, Controller, and Landing Navigation

- [x] 2.1 Add an aged receivables report controller and route gated by `saleReports.access`.
- [x] 2.2 Add the Reports module index Blade view that mounts the aged receivables Livewire component.
- [x] 2.3 Update the Reports landing Penjualan tab so `Usia piutang` links to the new route and no longer renders as a placeholder.
- [x] 2.4 Preserve unauthorized behavior so users without `saleReports.access` cannot access the route or see the card.

## 3. Livewire UI

- [x] 3.1 Add the aged receivables Livewire component with as-of date, period preset, advanced filters, applied-filter snapshot state, and pagination.
- [x] 3.2 Add the report Blade view with columns `Customer`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari`.
- [x] 3.3 Render `Total Piutang (semua pelanggan)` subtotals from the filtered aggregate result.
- [x] 3.4 Add empty, loading, validation, and stale-export feedback consistent with existing Reports Livewire screens.

## 4. Exports

- [x] 4.1 Add an aged receivables export class supporting XLSX, CSV, and PDF output from the applied filters.
- [x] 4.2 Ensure CSV starts with the table header row and contains one row per exported customer without metadata rows.
- [x] 4.3 Ensure XLSX includes company name, `Piutang`, selected as-of date, `(dalam IDR)`, table headers, customer rows, and subtotal row.
- [x] 4.4 Ensure PDF export contains the same customer rows, bucket totals, and subtotal as the filtered report result.
- [x] 4.5 Block exports before filters are applied or after pending filters diverge from the latest applied snapshot.

## 5. Verification

- [x] 5.1 Add service tests for bucket boundaries at 0, 30, 31, 60, 61, 90, and 91 days.
- [x] 5.2 Add service tests for active payments before/on/after the as-of date and invalidated payment exclusion.
- [x] 5.3 Add tests for tenant scoping, customer filter, tag any/all filter, sorting, and zero-balance customer exclusion.
- [x] 5.4 Add Livewire/feature tests for route access, landing card activation, filter application, stale export blocking, and rendered subtotal values.
- [x] 5.5 Add export tests for CSV plain-table shape and XLSX/PDF metadata/subtotal parity.
- [x] 5.6 Run focused report tests for aged receivables and related landing/customer receivables coverage.

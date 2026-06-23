# sales-tax-report Specification

## Purpose

Provide a `Laporan Pajak Penjualan` report under the Reports module that aggregates persisted tax contributions from approved-or-later Sales and Purchases within a selected date range, grouped by tax identity and transaction type, with a grouped on-screen display, net per-tax subtotals, and CSV/XLSX exports.

## Requirements

### Requirement: Sales tax report access

The system SHALL provide a `Laporan Pajak Penjualan` report page under the Reports module, reachable from an authenticated report route and accessible only to users with `reports.access`.

#### Scenario: Authorized user opens sales tax report

- **WHEN** a user with `reports.access` requests the sales tax report route
- **THEN** the system renders the `Laporan Pajak Penjualan` report page
- **AND** the page indicates the report currency as `(dalam IDR)`

#### Scenario: Unauthorized user is denied

- **WHEN** a user without `reports.access` requests the sales tax report route
- **THEN** the system denies access

### Requirement: Sales tax report filters

The report SHALL support `Tanggal Mulai`, `Tanggal Selesai`, and period presets for Tanggal, Hari ini, Minggu ini, Bulan ini, Tahun ini, Kemarin, Minggu lalu, Bulan lalu, and Tahun lalu. The report SHALL apply results only after the user triggers the Filter action.

#### Scenario: Default period is current month

- **WHEN** a user opens the report without explicit date filters
- **THEN** `Tanggal Mulai` defaults to the first day of the current month
- **AND** `Tanggal Selesai` defaults to the last day of the current month

#### Scenario: Year preset fills full calendar year

- **WHEN** the user selects the Tahun ini preset on June 23, 2026
- **THEN** `Tanggal Mulai` is set to January 1, 2026
- **AND** `Tanggal Selesai` is set to December 31, 2026

#### Scenario: Invalid date range is rejected

- **WHEN** the user applies a `Tanggal Selesai` earlier than `Tanggal Mulai`
- **THEN** the report rejects the filter
- **AND** the report MUST NOT export using the invalid range

### Requirement: Sales tax report row inclusion

The report SHALL include taxable Sales and Purchase detail rows dated within the inclusive selected date range, scoped to the active `setting_id`, and tied to approved or later operational documents. Drafted, waiting-approval, rejected, and archived documents SHALL be excluded.

#### Scenario: Approved sale detail is included as Penjualan

- **WHEN** an approved-or-later Sale has a taxable sale detail dated within the selected range and active setting
- **THEN** the report includes the detail's tax contribution under transaction type `Penjualan`

#### Scenario: Approved purchase detail is included as Pembelian

- **WHEN** an approved-or-later Purchase has a taxable purchase detail dated within the selected range and active setting
- **THEN** the report includes the detail's tax contribution under transaction type `Pembelian`

#### Scenario: Unapproved document is excluded

- **WHEN** a Sale or Purchase is drafted, waiting approval, rejected, or archived
- **THEN** its detail rows are not included in the report totals

#### Scenario: Other setting data is excluded

- **WHEN** a taxable Sale or Purchase belongs to a different `setting_id`
- **THEN** it is excluded from the report and exports

### Requirement: Sales tax report aggregation

The report SHALL aggregate included rows by tax identity/name and transaction type. For each aggregate row, DPP SHALL be calculated from persisted detail values as `max(0, sub_total - product_tax_amount)`, `Rate Pajak` SHALL use the persisted or related numeric tax rate, and `Total Pajak` SHALL sum persisted `product_tax_amount`. The report SHALL NOT recompute historical tax using current tax master values.

#### Scenario: Tax name and rate remain distinct

- **WHEN** a tax is named `PPN 12%` and its numeric rate is `11.0`
- **THEN** the report group label is `PPN 12%`
- **AND** the aggregate row displays `Rate Pajak` as `11.0`

#### Scenario: Sales detail contributes persisted tax amount

- **WHEN** an included sale detail has `sub_total` 700000 and `product_tax_amount` 63636.36
- **THEN** the report adds 636363.64 to DPP for that tax and transaction type
- **AND** the report adds 63636.36 to Total Pajak for that tax and transaction type

#### Scenario: Purchase detail contributes persisted tax amount

- **WHEN** an included purchase detail has `sub_total` 2562424312.70 and `product_tax_amount` 253933940.90
- **THEN** the report adds 2308490371.80 to DPP for that tax and transaction type
- **AND** the report adds 253933940.90 to Total Pajak for that tax and transaction type

### Requirement: Sales tax report grouped display

The on-screen report SHALL render columns `Tanggal`, `DPP`, `Rate Pajak`, and `Total Pajak`. The first column SHALL contain tax group headers and indented transaction labels. For each tax group, the report SHALL render a subtotal row whose amount is total `Penjualan` tax minus total `Pembelian` tax for that tax group.

#### Scenario: Tax group with only sales shows sales tax subtotal

- **WHEN** a tax group has `Penjualan` total tax 63636.36 and no `Pembelian` row
- **THEN** the report renders a subtotal of 63636.36 for that tax group

#### Scenario: Tax group with sales and purchases shows net subtotal

- **WHEN** a tax group has `Penjualan` total tax 312726699.31 and `Pembelian` total tax 253933940.90
- **THEN** the report renders a subtotal of 58792758.41 or the correctly rounded two-decimal equivalent

#### Scenario: Empty result displays empty state

- **WHEN** the applied filters match no taxable rows
- **THEN** the report shows an empty-state message
- **AND** the report does not render stale rows from a previous filter

### Requirement: Sales tax report exports

The report SHALL allow authorized users to export the last successfully applied result to CSV and XLSX. Exports SHALL be refused when filters have changed since the last successful Filter action.

#### Scenario: CSV export contains flat aggregate rows

- **WHEN** a user exports CSV after applying filters
- **THEN** the CSV headings are `Nama Pajak`, `Transaksi`, `DPP`, `Rate Pajak`, and `Total Pajak`
- **AND** the CSV contains one flat row per tax and transaction type
- **AND** the CSV does not include company metadata, tax group header rows, blank separator rows, or subtotal rows

#### Scenario: XLSX export includes report metadata and grouped structure

- **WHEN** a user exports XLSX after applying filters
- **THEN** the workbook includes company name, report title `Laporan Pajak Penjualan`, selected date range, and `(dalam IDR)` metadata above the table
- **AND** the workbook includes table headers `Tanggal`, `DPP`, `Rate Pajak`, and `Total Pajak`
- **AND** the workbook includes tax group headers, transaction rows, subtotal rows, and blank separator rows matching the on-screen grouped structure

#### Scenario: Export blocked after filter drift

- **WHEN** the user changes the date filters after applying filters but before exporting
- **THEN** the export is refused with a message asking the user to apply filters again

#### Scenario: Export filenames identify report and period

- **WHEN** a user exports the report for January 1, 2026 through December 31, 2026
- **THEN** the CSV filename identifies `sales_tax_report` and the selected date range
- **AND** the XLSX filename identifies `SalesTaxReport` and the selected date range

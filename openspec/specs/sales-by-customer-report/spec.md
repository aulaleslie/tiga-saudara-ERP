# sales-by-customer-report Specification

## Purpose

Provide a "Penjualan Per Customer" report that lists sale detail lines grouped by customer, with filtering, running subtotals, tax/discount row expansion, and snapshot-validated Excel/CSV export, scoped to the active setting.

## Requirements

### Requirement: Per-customer sales report

The system SHALL provide a "Penjualan Per Customer" report that lists sale detail lines grouped by customer, scoped to the current `setting_id`, reachable via `reports.sale-by-customer.index`, gated by `saleReports.access`, and restricted to non-archived Sales whose exact status is `DISPATCHED`. The status restriction SHALL apply to screen rows, customer totals, sorting, pagination, running totals, XLSX exports, and CSV exports.

#### Scenario: Report renders fully dispatched sales grouped by customer

- **WHEN** a user with `saleReports.access` applies filters and an otherwise matching Sale has exact status `DISPATCHED`
- **THEN** its sale detail lines are listed and grouped by customer for the selected date range
- **AND** its amounts contribute to all report totals and exports

#### Scenario: Sales that are not fully dispatched are excluded

- **WHEN** an otherwise matching Sale has status `DRAFTED`, `WAITING_APPROVAL`, `APPROVED`, `REJECTED`, or `DISPATCHED PARTIALLY`
- **THEN** its detail lines MUST NOT appear in screen results or XLSX/CSV exports
- **AND** its amounts MUST NOT contribute to customer totals, sorting, pagination, running totals, or the grand total

#### Scenario: Returned lifecycle states are excluded

- **WHEN** an otherwise matching Sale has status `RETURNED PARTIALLY` or `RETURNED`
- **THEN** its detail lines MUST NOT appear in screen results or XLSX/CSV exports
- **AND** its amounts MUST NOT contribute to customer totals, sorting, pagination, running totals, or the grand total

#### Scenario: Access is gated by the shared sales reports permission

- **WHEN** a user lacks `saleReports.access`
- **THEN** the route returns 403

### Requirement: Customer, tag, and category filters

The report SHALL support multi-select searchable filters for customers, tags, and product categories, each shown as removable pills.

#### Scenario: Filtering by category

- **WHEN** the user selects one or more product categories and applies filters
- **THEN** only sale lines whose product belongs to a selected category are included

#### Scenario: Search requires a minimum query length

- **WHEN** a filter search term is shorter than 2 characters
- **THEN** no options are suggested

### Requirement: Tag and category match logic

The report SHALL let the user choose "Salah satu" (any) or "Semua" (all) match logic for tags and for categories independently.

#### Scenario: All-match logic

- **WHEN** category logic is "Semua" and multiple categories are selected
- **THEN** only lines satisfying all selected categories are included

#### Scenario: Any-match logic

- **WHEN** tag logic is "Salah satu" and multiple tags are selected
- **THEN** lines matching at least one selected tag are included

### Requirement: Running per-customer subtotals

The report SHALL display a running subtotal per customer down the result rows, carrying the accumulated total across pagination boundaries.

#### Scenario: Subtotal carries across pages

- **WHEN** a customer's lines span more than one page
- **THEN** the running subtotal on the next page continues from where the previous page ended rather than resetting

### Requirement: Snapshot-validated export

The report SHALL export Excel or CSV only when the applied filters match the snapshot captured at the last Filter action.

#### Scenario: Export blocked before filtering

- **WHEN** no valid snapshot exists for the current filters
- **THEN** export is refused with a message asking the user to apply filters first

### Requirement: Sales reports menu structure

The sales reports navigation SHALL present a "Penjualan" dropdown containing Daftar Penjualan, Penjualan Per Customer, and Laporan Penjualan Global, mirroring the purchase reports "Pembelian" dropdown.

#### Scenario: Menu exposes the per-customer report

- **WHEN** a user with `saleReports.access` opens the reports menu
- **THEN** a "Penjualan Per Customer" link to `reports.sale-by-customer.index` appears under the Penjualan dropdown

### Requirement: Sales by customer tax row expansion
The system SHALL render and export a separate `Pajak` row immediately after a sale detail's product row when the persisted sale detail has `product_tax_amount > 0`.

#### Scenario: Taxed sale detail displays product and tax rows
- **WHEN** a matching sale detail has `sub_total` 100000 and `product_tax_amount` 11000
- **THEN** the report displays a product row with `Nominal tagihan` 100000
- **AND** the report displays a following row with `Nama produk` equal to `Pajak`
- **AND** the `Pajak` row has `Nominal tagihan` 11000

#### Scenario: Untaxed sale detail displays only product row
- **WHEN** a matching sale detail has `product_tax_amount` 0
- **THEN** the report displays the sale detail product row
- **AND** the report does not display a `Pajak` row for that detail

#### Scenario: Tax row uses persisted amount regardless of current PKP setting
- **WHEN** a matching sale detail has `product_tax_amount` greater than 0
- **AND** the current setting has any `is_pkp` value
- **THEN** the report displays the `Pajak` row
- **AND** the report does not recompute tax from current tax settings

### Requirement: Sales by customer export columns
The system SHALL omit `Keterangan` from `Penjualan Per Customer` Excel and CSV exports while retaining the on-screen `Keterangan` column.

#### Scenario: Sales Excel export omits Keterangan
- **WHEN** a user exports `Penjualan Per Customer` as XLSX
- **THEN** the exported columns do not include `Keterangan`

#### Scenario: Sales CSV export omits Keterangan
- **WHEN** a user exports `Penjualan Per Customer` as CSV
- **THEN** the exported columns do not include `Keterangan`

#### Scenario: Sales UI retains Keterangan
- **WHEN** a user views `Penjualan Per Customer` in the browser
- **THEN** the table still includes the `Keterangan` column

### Requirement: Running per-customer subtotals
The report SHALL display a running subtotal per customer down the expanded result rows, carrying the accumulated total across pagination boundaries and including persisted sale detail tax rows.

#### Scenario: Subtotal carries across pages
- **WHEN** a customer's lines span more than one page
- **THEN** the running subtotal on the next page continues from where the previous page ended rather than resetting

#### Scenario: Running total includes tax row
- **WHEN** a customer group has a product row with `Nominal tagihan` 100000 followed by a `Pajak` row with `Nominal tagihan` 11000
- **THEN** the product row's running subtotal is 100000
- **AND** the `Pajak` row's running subtotal is 111000

#### Scenario: Total-based sorting includes tax amounts
- **WHEN** a user sorts `Penjualan Per Customer` by customer total
- **THEN** each customer total used for ordering includes matching sale detail `sub_total` and `product_tax_amount`

### Requirement: Document discount row in expansion

The report SHALL emit exactly one document `Diskon` row per invoice whose `Sale.discount_amount` is greater than zero, placed after that invoice's product detail rows and before its `Pajak` row, with `Nama produk` set to `Diskon` and `Nominal tagihan` set to the negative of the document discount amount. The discount SHALL reduce the running `Total nominal tagihan` so the invoice's rows reconcile to the document total. Both the on-screen rows and the exported rows SHALL include this discount row identically.

#### Scenario: Discounted single-line invoice expands to three rows

- **WHEN** a sale has one detail line, a positive tax amount, and `discount_amount` of 45045.05
- **THEN** the report shows a product/DPP row, then a `Diskon` row whose `Nominal tagihan` is -45045.05, then a `Pajak` row
- **AND** the `Total nominal tagihan` after the `Pajak` row equals the sale total

#### Scenario: Discount row appears once for a multi-line invoice

- **WHEN** a sale has three detail lines and a single positive `discount_amount`
- **THEN** the report shows the three product/DPP rows followed by exactly one `Diskon` row for the invoice
- **AND** the running total after the discount row is reduced by the document discount amount once

#### Scenario: No discount row when the invoice has no discount

- **WHEN** a sale has `discount_amount` of 0
- **THEN** the report shows no `Diskon` row for that invoice

#### Scenario: Export matches on-screen discount row

- **WHEN** a discounted invoice is exported
- **THEN** the exported rows contain the same `Diskon` row, in the same position, as the on-screen report

### Requirement: Archived sales are excluded from sales by customer reporting

The system SHALL exclude archived sales and their detail rows from Penjualan Per Customer screen results, customer totals, pagination, XLSX exports, and CSV exports, even when the report query joins the sales table directly.

#### Scenario: Archived matching sale is omitted

- **WHEN** a sale matches the active setting, date range, and selected report filters but has a non-null `archived_at`
- **THEN** its detail rows MUST NOT appear in the Penjualan Per Customer screen results
- **AND** its amounts MUST NOT contribute to customer totals or the grand total
- **AND** its detail rows MUST NOT appear in XLSX or CSV exports

#### Scenario: Active matching sale remains included

- **WHEN** a non-archived sale matches the active setting, date range, and selected report filters
- **THEN** its detail rows SHALL remain available to the Penjualan Per Customer screen and exports

### Requirement: Sales by customer exports handle effective dates safely

The system SHALL export each included sale row using the same effective sale reporting date used by report filtering and ordering. XLSX and CSV generation MUST NOT fail with a date parsing exception when an export row has no parseable date value.

#### Scenario: Reporting-date override is exported

- **WHEN** an included active sale has a reporting-date override
- **THEN** each exported row for that sale SHALL contain the override formatted as `d/m/Y`

#### Scenario: Original date is exported without an override

- **WHEN** an included active sale has no reporting-date override
- **THEN** each exported row for that sale SHALL contain its original sale date formatted as `d/m/Y`

#### Scenario: Missing mapped date does not abort export generation

- **WHEN** an export row reaches date formatting without a parseable date value
- **THEN** the exporter MUST emit a neutral date placeholder for that row
- **AND** XLSX or CSV generation MUST continue without a Carbon date parsing exception

### Requirement: Penjualan Per Customer shows loading feedback while filtering

The system SHALL show visible loading feedback and block duplicate submission while the Penjualan Per Customer filter apply action is in progress, without affecting unrelated Livewire requests.

#### Scenario: Filter apply shows spinner and blocks the table

- **WHEN** the user triggers `applyFilters` (from the inline Filter button or the drawer's Filter button)
- **THEN** both Filter buttons SHALL show a spinner and be disabled for the duration of the request
- **AND** the report table SHALL be visually dimmed and non-interactive (pointer events blocked) after a short delay, to avoid flicker on fast responses
- **AND** a centered loading indicator SHALL appear above the table while the request is in progress

#### Scenario: Unrelated requests do not trigger filter loading state

- **WHEN** the user paginates, sorts, or performs a customer/category/tag autocomplete search
- **THEN** the table SHALL NOT be dimmed or blocked
- **AND** the Filter buttons SHALL NOT show a spinner

### Requirement: Penjualan Per Customer shows loading feedback while exporting

The system SHALL show visible loading feedback on the Ekspor dropdown trigger while an export action is in progress and SHALL prevent duplicate export submissions, without dimming the report table.

#### Scenario: Export shows spinner on the dropdown trigger

- **WHEN** the user triggers `exportExcel` or `exportCsv`
- **THEN** the Ekspor dropdown trigger button SHALL show a spinner in place of its normal icon and SHALL be disabled
- **AND** both export actions (Excel and CSV) SHALL be disabled for the duration of the request
- **AND** the report table SHALL NOT be dimmed or blocked during export

#### Scenario: Export loading state clears after completion

- **WHEN** an export request finishes (success or failure)
- **THEN** the Ekspor dropdown trigger SHALL return to its normal icon and enabled state
- **AND** both export actions SHALL become enabled again


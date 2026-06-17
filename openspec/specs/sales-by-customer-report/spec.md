## ADDED Requirements

### Requirement: Per-customer sales report

The system SHALL provide a "Penjualan Per Customer" report that lists sale detail lines grouped by customer, scoped to the current `setting_id`, reachable via `reports.sale-by-customer.index` and gated by `saleReports.access`.

#### Scenario: Report renders grouped by customer

- **WHEN** a user with `saleReports.access` applies filters
- **THEN** sale detail lines are listed and grouped by customer for the selected date range

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
## Requirements
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


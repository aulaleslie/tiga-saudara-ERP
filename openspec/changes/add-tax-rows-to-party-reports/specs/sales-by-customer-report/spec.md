## ADDED Requirements

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

## MODIFIED Requirements

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

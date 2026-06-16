## ADDED Requirements

### Requirement: Purchase by supplier tax row expansion
The system SHALL render a separate `Pajak` row immediately after a purchase detail's product row when the persisted purchase detail has `product_tax_amount > 0`.

#### Scenario: Taxed purchase detail displays product and tax rows
- **WHEN** a matching purchase detail has `sub_total` 11162162.16 and `product_tax_amount` 1227837.84
- **THEN** the report displays a product row with `Nominal tagihan` 11162162.16
- **AND** the report displays a following row with `Nama produk` equal to `Pajak`
- **AND** the `Pajak` row has `Nominal tagihan` 1227837.84

#### Scenario: Untaxed purchase detail displays only product row
- **WHEN** a matching purchase detail has `product_tax_amount` 0
- **THEN** the report displays the purchase detail product row
- **AND** the report does not display a `Pajak` row for that detail

#### Scenario: Tax row uses persisted amount regardless of current PKP setting
- **WHEN** a matching purchase detail has `product_tax_amount` greater than 0
- **AND** the current setting has any `is_pkp` value
- **THEN** the report displays the `Pajak` row
- **AND** the report does not recompute tax from current tax settings

## MODIFIED Requirements

### Requirement: Purchase by supplier monetary totals
The system SHALL use purchase detail `sub_total` for product-row `Nominal tagihan`, use purchase detail `product_tax_amount` for tax-row `Nominal tagihan`, and compute `Total nominal tagihan` as a running total per supplier group across all rendered product and tax rows.

#### Scenario: Product row nominal tagihan uses detail subtotal
- **WHEN** a purchase detail row has `sub_total` of 38880000
- **THEN** the product row's `Nominal tagihan` is 38880000

#### Scenario: Tax row nominal tagihan uses detail tax amount
- **WHEN** a purchase detail row has `product_tax_amount` of 4276800
- **THEN** the following `Pajak` row's `Nominal tagihan` is 4276800

#### Scenario: Total nominal tagihan includes tax rows in order
- **WHEN** a supplier group contains a product row with `Nominal tagihan` 11162162.16 followed by a `Pajak` row with `Nominal tagihan` 1227837.84
- **THEN** the product row's `Total nominal tagihan` is 11162162.16
- **AND** the `Pajak` row's `Total nominal tagihan` is 12390000.00

### Requirement: Purchase by supplier sorting and pagination
The system SHALL sort supplier report detail rows by transaction date descending within supplier groups and use normal detail-row pagination before tax-row expansion.

#### Scenario: Rows inside supplier group are date descending
- **WHEN** a supplier has matching purchases dated 2026-05-09 and 2026-05-18
- **THEN** the 2026-05-18 row appears before the 2026-05-09 row within that supplier group

#### Scenario: User sorts by supplier
- **WHEN** a user selects `Supplier` sorting and applies the filters
- **THEN** supplier groups are ordered by supplier name according to the selected sort direction

#### Scenario: User sorts by total purchase
- **WHEN** a user selects `Total pembelian` sorting and applies the filters
- **THEN** supplier groups are ordered by the total purchase amount for each supplier according to the selected sort direction
- **AND** each supplier total used for ordering includes matching purchase detail `sub_total` and `product_tax_amount`

#### Scenario: Report uses normal detail-row pagination
- **WHEN** the filtered report has more detail rows than the page size
- **THEN** the system paginates by matching purchase detail rows
- **AND** a supplier group may continue on a later page
- **AND** a displayed page may contain more rendered table rows than the page size when tax rows are present

#### Scenario: Running total carries tax amounts across pages
- **WHEN** prior pages contain purchase details with `sub_total` and `product_tax_amount`
- **THEN** the first row on the next page continues from the sum of prior-page product and tax amounts for that supplier

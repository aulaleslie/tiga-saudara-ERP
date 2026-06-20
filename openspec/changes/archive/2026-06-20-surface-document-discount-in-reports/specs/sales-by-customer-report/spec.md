## ADDED Requirements

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

## ADDED Requirements

### Requirement: Document discount row in supplier expansion

The report SHALL emit exactly one document `Diskon` row per purchase invoice whose `Purchase.discount_amount` is greater than zero, placed after that invoice's product detail rows and before its `Pajak` row within the supplier group, with `Nama produk` set to `Diskon` and `Nominal tagihan` set to the negative of the document discount amount. The discount SHALL reduce the running `Total nominal tagihan` so the invoice's rows reconcile to the document total. Both the on-screen rows and the exported rows SHALL include this discount row identically.

#### Scenario: Discounted purchase expands with a discount row

- **WHEN** a purchase has a positive `discount_amount` and matches the report filters
- **THEN** the supplier group shows the purchase's product/DPP rows, then a `Diskon` row whose `Nominal tagihan` is the negative document discount, then any `Pajak` row
- **AND** the `Total nominal tagihan` for that purchase reconciles to the purchase total

#### Scenario: Discount row appears once for a multi-line purchase

- **WHEN** a purchase has three detail rows and a single positive `discount_amount`
- **THEN** the report shows the three product/DPP rows followed by exactly one `Diskon` row for the purchase

#### Scenario: No discount row when the purchase has no discount

- **WHEN** a purchase has `discount_amount` of 0
- **THEN** the report shows no `Diskon` row for that purchase

#### Scenario: Export matches on-screen discount row

- **WHEN** a discounted purchase is exported
- **THEN** the exported rows contain the same `Diskon` row, in the same position, as the on-screen report

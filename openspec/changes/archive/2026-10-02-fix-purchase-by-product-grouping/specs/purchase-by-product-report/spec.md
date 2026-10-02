# Spec Delta

## MODIFIED Requirements

### Requirement: Product aggregate presentation
The report SHALL present one aggregate row per `product_id` with columns for product code, product name, purchase quantity, unit, purchase value, and average purchase value. Display values for product code, product name, and unit SHALL be derived from the `products` and `units` master tables. The grouping SHALL use `purchase_details.product_id` as the sole grouping key — snapshot columns on `purchase_details` SHALL NOT participate in the GROUP BY clause.

#### Scenario: Product row displays aggregate columns
- **WHEN** a product has matching purchased quantities
- **THEN** the row displays `Kode produk / SKU`, `Nama produk`, `Qty pembelian`, `Unit`, `Nilai pembelian`, and `Nilai pembelian rata-rata`

#### Scenario: Product without code remains reportable
- **WHEN** a matching purchase detail has no product code
- **THEN** the report still includes the product row with a blank or fallback code display

#### Scenario: Total row is shown
- **WHEN** the report has one or more matching product rows
- **THEN** the report shows a total row summing `Nilai pembelian`

#### Scenario: Empty result state is shown
- **WHEN** filters match no purchase details
- **THEN** the report shows an empty state instead of totals

#### Scenario: Same product with mixed snapshot values produces one row
- **WHEN** a product has purchase detail rows where `purchase_details.product_name` or `purchase_details.unit_name` differ across rows (due to historical edits or NULL values)
- **THEN** the report produces exactly one aggregated row for that `product_id`
- **AND** the displayed product name and unit come from the current master `products` and `units` tables

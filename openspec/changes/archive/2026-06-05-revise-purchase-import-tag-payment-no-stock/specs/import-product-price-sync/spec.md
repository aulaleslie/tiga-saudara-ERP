## MODIFIED Requirements

### Requirement: Purchase import synchronizes purchase prices across settings
The purchase importer SHALL synchronize each processed row's final tax-included unit price into `product_prices` for every setting as last purchase price data, while preserving existing average purchase price values.

#### Scenario: Imported purchase price updates every setting
- **WHEN** a purchase import row is processed successfully with a positive final tax-included unit price
- **THEN** the imported product MUST have a `product_prices` row for every setting
- **AND** each row's `last_purchase_price` MUST equal the imported final tax-included unit price
- **AND** each existing row's `average_purchase_price` MUST remain unchanged

#### Scenario: New purchase price row defaults average to zero
- **WHEN** a purchase import row is processed successfully for a product and setting that has no existing `product_prices` row
- **THEN** the importer MUST create the missing `product_prices` row
- **AND** the new row's `last_purchase_price` MUST equal the imported final tax-included unit price
- **AND** the new row's `average_purchase_price` MUST be `0`

#### Scenario: Purchase import does not update selling prices
- **WHEN** a purchase import row is processed successfully
- **THEN** the importer MUST NOT overwrite `sale_price`, `tier_1_price`, or `tier_2_price` from the purchase unit price

### Requirement: Duplicate-skipped imports do not synchronize product prices
The purchase and sales importers SHALL NOT synchronize or backfill `product_prices` for import rows skipped because their source invoice already exists.

#### Scenario: Duplicate purchase import does not backfill purchase prices
- **WHEN** a purchase import invoice is skipped as a duplicate
- **THEN** the importer MUST NOT update `last_purchase_price` or `average_purchase_price` for any product in the skipped rows

#### Scenario: Duplicate sales import does not backfill selling prices
- **WHEN** a sales import invoice is skipped as a duplicate
- **THEN** the importer MUST NOT update `sale_price`, `tier_1_price`, or `tier_2_price` for any product in the skipped rows

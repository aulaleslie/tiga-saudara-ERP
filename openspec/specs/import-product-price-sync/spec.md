## ADDED Requirements

### Requirement: Purchase import synchronizes purchase prices across settings
The purchase importer SHALL synchronize each processed row's final tax-included unit price into `product_prices` for every setting as purchase price data.

#### Scenario: Imported purchase price updates every setting
- **WHEN** a purchase import row is processed successfully with a positive final tax-included unit price
- **THEN** the imported product MUST have a `product_prices` row for every setting
- **AND** each row's `last_purchase_price` MUST equal the imported final tax-included unit price
- **AND** each row's `average_purchase_price` MUST equal the importer-calculated weighted average purchase price

#### Scenario: Purchase import does not update selling prices
- **WHEN** a purchase import row is processed successfully
- **THEN** the importer MUST NOT overwrite `sale_price`, `tier_1_price`, or `tier_2_price` from the purchase unit price

### Requirement: Sales import synchronizes selling prices across settings and tiers
The sales importer SHALL synchronize each processed row's positive final tax-included unit price into `product_prices` for every setting as base and tier selling price data.

#### Scenario: Imported sales price updates every setting and tier
- **WHEN** a sales import row is processed successfully with a positive final tax-included unit price
- **THEN** the imported product MUST have a `product_prices` row for every setting
- **AND** each row's `sale_price` MUST equal the imported final tax-included unit price
- **AND** each row's `tier_1_price` MUST equal the imported final tax-included unit price
- **AND** each row's `tier_2_price` MUST equal the imported final tax-included unit price

#### Scenario: Latest processed sales row wins
- **WHEN** multiple successfully processed sales import rows for the same product have different positive final tax-included unit prices
- **THEN** `sale_price`, `tier_1_price`, and `tier_2_price` across every setting MUST equal the price from the row processed last

### Requirement: Sales import preserves catalog prices for zero-value import prices
The sales importer SHALL NOT overwrite `product_prices` selling fields when an imported row's final tax-included unit price is zero or blank.

#### Scenario: Zero sales price does not overwrite catalog price
- **WHEN** a sales import row is processed with a zero or blank final tax-included unit price
- **THEN** the sale detail MUST retain the imported calculated zero unit price
- **AND** existing `product_prices.sale_price`, `product_prices.tier_1_price`, and `product_prices.tier_2_price` values MUST remain unchanged

### Requirement: Duplicate-skipped imports do not synchronize product prices
The purchase and sales importers SHALL NOT synchronize or backfill `product_prices` for import rows skipped because their source invoice already exists.

#### Scenario: Duplicate purchase import does not backfill purchase prices
- **WHEN** a purchase import invoice is skipped as a duplicate
- **THEN** the importer MUST NOT update `last_purchase_price` or `average_purchase_price` for any product in the skipped rows

#### Scenario: Duplicate sales import does not backfill selling prices
- **WHEN** a sales import invoice is skipped as a duplicate
- **THEN** the importer MUST NOT update `sale_price`, `tier_1_price`, or `tier_2_price` for any product in the skipped rows

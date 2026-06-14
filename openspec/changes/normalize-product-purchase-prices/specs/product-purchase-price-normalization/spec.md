## ADDED Requirements

### Requirement: Operator can dry-run product purchase price normalization
The system SHALL provide an artisan command that previews product purchase price normalization without modifying data by default.

#### Scenario: Dry-run does not write product price rows
- **WHEN** the operator runs the normalization command without `--write`
- **THEN** the system SHALL calculate eligible product purchase price changes
- **AND** the system SHALL report the number of products considered, products skipped, `product_prices` rows that would be created, and `product_prices` rows that would be updated
- **AND** the system MUST NOT create or update any `product_prices` rows

### Requirement: Normalization uses historical received purchase costs
The normalization command SHALL calculate product purchase costs from non-archived purchase details whose parent purchase status is `RECEIVED` or `RECEIVED PARTIALLY`.

#### Scenario: Approved received quantities determine normal purchase cost
- **WHEN** a stock-managed product has purchase details with approved received-note detail quantities
- **THEN** the command SHALL use the approved `quantity_received` values as the eligible quantities
- **AND** the command SHALL use the purchase detail `price` as the tax-included unit purchase cost

#### Scenario: Purchase detail quantity is used when no approved receipt exists
- **WHEN** a stock-managed product has a purchase detail on a non-archived purchase with status `RECEIVED` or `RECEIVED PARTIALLY`
- **AND** that purchase detail has no approved received-note detail quantities
- **THEN** the command SHALL use the purchase detail `quantity` as the eligible quantity
- **AND** the command SHALL use the purchase detail `price` as the tax-included unit purchase cost

#### Scenario: Ineligible purchase documents are excluded
- **WHEN** purchase details belong to archived purchases, draft purchases, waiting-approval purchases, approved-but-not-received purchases, or rejected purchases
- **THEN** the command MUST exclude those purchase details from purchase cost calculations

#### Scenario: Purchase returns are ignored
- **WHEN** a product has purchase return records
- **THEN** the command MUST NOT subtract or otherwise adjust eligible purchase quantities or costs using purchase return data

### Requirement: Normalization calculates global product purchase cost snapshots
The normalization command SHALL treat purchase cost as global per stock-managed product and calculate one average purchase price and one last purchase price per eligible product.

#### Scenario: Weighted average is calculated from eligible cost events
- **WHEN** a stock-managed product has one or more eligible positive purchase quantities
- **THEN** the command SHALL calculate `average_purchase_price` as the sum of each eligible unit purchase cost multiplied by eligible quantity divided by the total eligible quantity
- **AND** the command SHALL round the calculated average purchase price to two decimal places

#### Scenario: Latest eligible event determines last purchase price
- **WHEN** a stock-managed product has multiple eligible purchase cost events
- **THEN** the command SHALL set `last_purchase_price` from the latest eligible event ordered by approved receiving timestamp when present, then purchase date, then stable database identifiers

#### Scenario: Products without eligible cost are skipped
- **WHEN** a stock-managed product has no eligible positive purchase quantity
- **THEN** the command SHALL skip purchase price normalization for that product

#### Scenario: Non-stock-managed products are skipped
- **WHEN** a product is not stock managed
- **THEN** the command MUST NOT create or update `product_prices` rows for that product

### Requirement: Normalization synchronizes purchase costs to every setting
When executed with `--write`, the normalization command SHALL write each eligible product's calculated purchase cost snapshots to every setting's `product_prices` row.

#### Scenario: Existing setting rows receive normalized purchase costs
- **WHEN** an eligible product has existing `product_prices` rows for one or more settings
- **AND** the operator runs the normalization command with `--write`
- **THEN** every existing setting row for that product SHALL have `last_purchase_price` set to the calculated last purchase price
- **AND** every existing setting row for that product SHALL have `average_purchase_price` set to the calculated average purchase price
- **AND** existing `sale_price`, `tier_1_price`, `tier_2_price`, `purchase_tax_id`, and `sale_tax_id` values MUST remain unchanged

#### Scenario: Missing setting rows are created
- **WHEN** an eligible product is missing a `product_prices` row for a setting
- **AND** the operator runs the normalization command with `--write`
- **THEN** the system SHALL create the missing `product_prices` row
- **AND** the new row SHALL have `last_purchase_price` set to the calculated last purchase price
- **AND** the new row SHALL have `average_purchase_price` set to the calculated average purchase price

#### Scenario: Missing rows copy existing same-product sales metadata when available
- **WHEN** the command creates a missing `product_prices` row for an eligible product
- **AND** the product has an existing same-product `product_prices` row with a non-zero `sale_price`
- **THEN** the new row SHALL copy `sale_price`, `purchase_tax_id`, and `sale_tax_id` from that existing row
- **AND** the new row SHALL copy positive `tier_1_price` and `tier_2_price` values from that existing row
- **AND** the new row SHALL default any zero or missing tier price to the copied `sale_price`

#### Scenario: Missing rows default sales metadata when no template exists
- **WHEN** the command creates a missing `product_prices` row for an eligible product
- **AND** the product has no existing same-product `product_prices` row with a non-zero `sale_price`
- **THEN** the new row SHALL set `sale_price`, `tier_1_price`, and `tier_2_price` to `0`
- **AND** the new row SHALL set `purchase_tax_id` and `sale_tax_id` to null

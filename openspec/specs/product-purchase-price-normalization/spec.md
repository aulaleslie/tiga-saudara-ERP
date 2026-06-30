# product-purchase-price-normalization Specification

## Purpose
Normalize product purchase costs from DPP unit prices rather than tax-included prices, and calculate costs separately per setting bucket (CV TIGA NUSA COMPUTER, CV TOP IT INTERNUSA, and REST/global) so special companies maintain isolated historical purchase baselines while other settings share a global result.
## Requirements
### Requirement: Operator can dry-run product purchase price normalization
The system SHALL provide an artisan command that previews product purchase price normalization without modifying data by default.

#### Scenario: Dry-run does not write product price rows
- **WHEN** the operator runs the normalization command without `--write`
- **THEN** the system SHALL calculate eligible product purchase price changes
- **AND** the system SHALL report the number of products considered, products skipped, `product_prices` rows that would be created, and `product_prices` rows that would be updated
- **AND** the system MUST NOT create or update any `product_prices` rows

### Requirement: Normalization uses historical received purchase costs
The normalization command SHALL calculate product purchase costs from non-archived purchase details whose parent purchase status is `RECEIVED` or `RECEIVED PARTIALLY`, using DPP unit cost for normalized purchase price snapshots.

#### Scenario: Approved received quantities determine normal purchase cost
- **WHEN** a stock-managed product has purchase details with approved received-note detail quantities
- **THEN** the command SHALL use the approved `quantity_received` values as the eligible quantities
- **AND** the command SHALL calculate eligible unit purchase cost from purchase detail DPP by subtracting `product_tax_amount` from `sub_total` and dividing by the purchase detail `quantity`

#### Scenario: Purchase detail quantity is used when no approved receipt exists
- **WHEN** a stock-managed product has a purchase detail on a non-archived purchase with status `RECEIVED` or `RECEIVED PARTIALLY`
- **AND** that purchase detail has no approved received-note detail quantities
- **THEN** the command SHALL use the purchase detail `quantity` as the eligible quantity
- **AND** the command SHALL calculate eligible unit purchase cost from purchase detail DPP by subtracting `product_tax_amount` from `sub_total` and dividing by the purchase detail `quantity`

#### Scenario: Ineligible purchase documents are excluded
- **WHEN** purchase details belong to archived purchases, draft purchases, waiting-approval purchases, approved-but-not-received purchases, or rejected purchases
- **THEN** the command MUST exclude those purchase details from purchase cost calculations

#### Scenario: Purchase returns are ignored
- **WHEN** a product has purchase return records
- **THEN** the command MUST NOT subtract or otherwise adjust eligible purchase quantities or costs using purchase return data

### Requirement: Normalization calculates bucketed product purchase cost snapshots
The normalization command SHALL calculate historical product purchase costs in setting buckets for stock-managed products, isolating `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA` from the REST/global bucket.

#### Scenario: Weighted average is calculated from eligible DPP cost events
- **WHEN** a stock-managed product has one or more eligible positive purchase quantities in a normalization bucket
- **THEN** the command SHALL calculate that bucket's `average_purchase_price` as the sum of each eligible DPP unit purchase cost multiplied by eligible quantity divided by the total eligible quantity
- **AND** the command SHALL round the calculated average purchase price to two decimal places

#### Scenario: Latest eligible event determines DPP last purchase price
- **WHEN** a stock-managed product has multiple eligible purchase cost events in a normalization bucket
- **THEN** the command SHALL set that bucket's `last_purchase_price` from the latest eligible DPP unit cost event ordered by approved receiving timestamp when present, then purchase date, then stable database identifiers

#### Scenario: Special company bucket is isolated
- **WHEN** an eligible purchase detail belongs to `CV TIGA NUSA COMPUTER`
- **THEN** the command SHALL include that cost event only in the Tiga Nusa normalization bucket
- **AND** the command MUST NOT include that cost event in the REST/global or Top IT normalization buckets

#### Scenario: Top IT bucket is isolated
- **WHEN** an eligible purchase detail belongs to `CV TOP IT INTERNUSA`
- **THEN** the command SHALL include that cost event only in the Top IT normalization bucket
- **AND** the command MUST NOT include that cost event in the REST/global or Tiga Nusa normalization buckets

#### Scenario: Other settings use REST/global bucket
- **WHEN** an eligible purchase detail belongs to any setting other than `CV TIGA NUSA COMPUTER` or `CV TOP IT INTERNUSA`
- **THEN** the command SHALL include that cost event in the REST/global normalization bucket
- **AND** the command MUST NOT include that cost event in either special-company bucket

#### Scenario: Products without any eligible bucket cost are skipped
- **WHEN** a stock-managed product has no eligible positive purchase quantity in any normalization bucket
- **THEN** the command SHALL skip purchase price normalization for that product

#### Scenario: Non-stock-managed products are skipped
- **WHEN** a product is not stock managed
- **THEN** the command MUST NOT create or update `product_prices` rows for that product

### Requirement: Normalization synchronizes purchase costs using bucket targets
When executed with `--write`, the normalization command SHALL write each eligible product's calculated purchase cost snapshots to every setting's `product_prices` row using the target bucket for that setting.

#### Scenario: Tiga Nusa setting receives isolated bucket result
- **WHEN** an eligible product has purchase cost history in the `CV TIGA NUSA COMPUTER` bucket
- **AND** the operator runs the normalization command with `--write`
- **THEN** the `CV TIGA NUSA COMPUTER` product price row SHALL have `last_purchase_price` set to the Tiga Nusa bucket's DPP last purchase price
- **AND** the `CV TIGA NUSA COMPUTER` product price row SHALL have `average_purchase_price` set to the Tiga Nusa bucket's DPP average purchase price

#### Scenario: Top IT setting receives isolated bucket result
- **WHEN** an eligible product has purchase cost history in the `CV TOP IT INTERNUSA` bucket
- **AND** the operator runs the normalization command with `--write`
- **THEN** the `CV TOP IT INTERNUSA` product price row SHALL have `last_purchase_price` set to the Top IT bucket's DPP last purchase price
- **AND** the `CV TOP IT INTERNUSA` product price row SHALL have `average_purchase_price` set to the Top IT bucket's DPP average purchase price

#### Scenario: Special setting falls back to REST/global result when its bucket is empty
- **WHEN** an eligible product has no purchase cost history in a special-company bucket
- **AND** the same product has purchase cost history in the REST/global bucket
- **AND** the operator runs the normalization command with `--write`
- **THEN** that special company's product price row SHALL have `last_purchase_price` set to the REST/global bucket's DPP last purchase price
- **AND** that special company's product price row SHALL have `average_purchase_price` set to the REST/global bucket's DPP average purchase price

#### Scenario: Non-special setting rows receive REST/global result
- **WHEN** an eligible product has purchase cost history in the REST/global bucket
- **AND** the operator runs the normalization command with `--write`
- **THEN** every non-special setting row for that product SHALL have `last_purchase_price` set to the REST/global bucket's DPP last purchase price
- **AND** every non-special setting row for that product SHALL have `average_purchase_price` set to the REST/global bucket's DPP average purchase price

#### Scenario: Existing setting rows preserve sales metadata
- **WHEN** an eligible product has existing `product_prices` rows for one or more settings
- **AND** the operator runs the normalization command with `--write`
- **THEN** each existing setting row for that product SHALL receive the purchase costs for its target bucket
- **AND** existing `sale_price`, `tier_1_price`, `tier_2_price`, `purchase_tax_id`, and `sale_tax_id` values MUST remain unchanged

#### Scenario: Missing setting rows are created with target bucket costs
- **WHEN** an eligible product is missing a `product_prices` row for a setting
- **AND** the operator runs the normalization command with `--write`
- **THEN** the system SHALL create the missing `product_prices` row
- **AND** the new row SHALL have `last_purchase_price` set to the calculated last purchase price for that setting's target bucket
- **AND** the new row SHALL have `average_purchase_price` set to the calculated average purchase price for that setting's target bucket

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

#### Scenario: Future purchase approval remains globally synchronized
- **WHEN** a purchase is approved through the normal purchase receiving workflow after normalization behavior is updated
- **THEN** the runtime purchase approval workflow SHALL continue to synchronize the resulting average purchase price globally across settings
- **AND** the runtime purchase approval workflow SHALL NOT apply special-company normalization buckets

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


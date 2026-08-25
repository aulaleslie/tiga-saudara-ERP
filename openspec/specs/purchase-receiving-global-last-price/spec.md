# purchase-receiving-global-last-price Specification

## Purpose
TBD - created by archiving change sync-cross-business-product-prices. Update Purpose after archive.
## Requirements
### Requirement: Approved receiving synchronizes last purchase price globally
When an ordinary purchase receiving note is approved with a positive received quantity, the system SHALL write the related purchase detail's existing last-purchase-price source value to `last_purchase_price` for that product in every business.

#### Scenario: Receiving updates existing business price rows
- **WHEN** an authorized user approves a receiving note detail with a positive received quantity
- **THEN** every existing `product_prices` row for the received product MUST have `last_purchase_price` set to the related purchase detail price

#### Scenario: Receiving creates missing business price rows
- **WHEN** an authorized user approves a receiving note for a product that lacks a `product_prices` row for one or more current businesses
- **THEN** the system MUST create the missing rows
- **AND** every created row's `last_purchase_price` MUST equal the related purchase detail price

#### Scenario: Multiple received products synchronize independently
- **WHEN** an approved receiving note contains positive received quantities for multiple products with different purchase detail prices
- **THEN** every business MUST receive the corresponding last purchase price for each product

### Requirement: Last purchase synchronization preserves unrelated prices
Global last purchase price synchronization SHALL NOT overwrite selling prices, tier prices, average purchase prices, or tax selections on existing business price rows.

#### Scenario: Existing unrelated fields remain unchanged
- **WHEN** receiving approval synchronizes a product's last purchase price across businesses
- **THEN** each existing row's `sale_price`, `tier_1_price`, `tier_2_price`, `average_purchase_price`, `sale_tax_id`, and `purchase_tax_id` MUST retain their prior values except for any separate existing receiving behavior that updates average purchase price

### Requirement: Last purchase synchronization follows receiving approval atomicity
Global last purchase price synchronization SHALL execute within the existing receiving approval transaction and SHALL occur only for details with a positive received quantity.

#### Scenario: Pending or rejected receiving does not synchronize
- **WHEN** a receiving note remains pending or is rejected
- **THEN** the receiving note MUST NOT synchronize `last_purchase_price` across businesses

#### Scenario: Zero received quantity does not synchronize
- **WHEN** a receiving note detail has no positive received quantity
- **THEN** that detail MUST NOT change the product's `last_purchase_price` rows

#### Scenario: Approval failure rolls back synchronization
- **WHEN** any part of receiving approval fails and its transaction is rolled back
- **THEN** no business price row from that approval MUST retain a last purchase price change


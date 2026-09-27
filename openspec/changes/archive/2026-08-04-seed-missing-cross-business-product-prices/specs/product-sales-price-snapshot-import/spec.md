## MODIFIED Requirements

### Requirement: Owner-specific selling-tier synchronization
For each valid matched row, the system SHALL update the matched product's `product_prices` record for the resolved owner setting and SHALL set all three selling tiers to the same imported `SellPrice` as part of the atomic price-and-stock snapshot mutation. The system SHALL then initialize missing price rows for that product in every other available setting without changing any existing other-setting price row.

#### Scenario: Existing owner price row is updated
- **WHEN** a row resolves to a product, owner setting, and positive selling price
- **AND** the `(product_id, setting_id)` price row exists
- **THEN** the system SHALL set `sale_price`, `tier_1_price`, and `tier_2_price` to the imported selling price in the transaction that applies the row's stock snapshot

#### Scenario: Owner price row is missing
- **WHEN** a row resolves to a product, owner setting, and positive selling price
- **AND** the `(product_id, setting_id)` price row does not exist
- **THEN** the system SHALL create that owner-specific price row
- **AND** `sale_price`, `tier_1_price`, and `tier_2_price` SHALL equal the imported selling price

#### Scenario: Missing other-setting price rows are seeded
- **WHEN** the resolved owner price is successfully applied for a product
- **AND** one or more other available settings lack a `product_prices` row for that product
- **THEN** the system SHALL create a price row for each missing setting in the same transaction
- **AND** each new row's `sale_price`, `tier_1_price`, and `tier_2_price` SHALL equal the resolved owner's imported selling price
- **AND** the new rows SHALL use the established zero purchase-price and null tax defaults

#### Scenario: Existing other-setting prices remain unchanged
- **WHEN** a row updates the resolved owner's price record
- **AND** another setting already has a price row for the same product
- **THEN** the system SHALL leave every value in that other setting's existing price row unchanged

#### Scenario: Later owner row updates only its owner
- **WHEN** an earlier source row seeded a missing price row for an owner setting
- **AND** a later source row resolves to that owner and product
- **THEN** the system SHALL update only that later row's resolved owner price to the later imported value
- **AND** the system SHALL leave prices in every other setting unchanged

#### Scenario: Non-selling price data remains unchanged
- **WHEN** a row updates or creates the resolved owner's price record
- **THEN** the import SHALL NOT overwrite `last_purchase_price`, `average_purchase_price`, `purchase_tax_id`, or `sale_tax_id` on an existing row
- **AND** the import SHALL NOT update legacy product price fields, bundle prices, or unit-conversion prices

#### Scenario: Reimported value already matches
- **WHEN** all three target selling tiers already equal the imported selling price
- **THEN** the row SHALL remain eligible to seed any missing other-setting price rows and apply its imported stock snapshot
- **AND** the system SHALL record that no resolved-owner price value changed

## MODIFIED Requirements

### Requirement: Bundle items SHALL configure informational item price
The Product Bundle item table SHALL provide a modifiable `Harga Informasi Item` value for each bundled item, backed by a new item-level column and treated as informational configuration data. Product selection SHALL autofill this value from active-setting non-tier pricing (`product_prices.sale_price`) for the selected product.

#### Scenario: Selecting an item defaults informational price from selected product
- **WHEN** a user selects a product in an `Item Paket` row
- **AND** the selected product has an active-setting `product_prices.sale_price`
- **THEN** the row's `Harga Informasi Item` field SHALL default to that sale price
- **AND** the user SHALL be able to change the value before saving

#### Scenario: Missing active-setting sale price is handled explicitly
- **WHEN** a user selects a product in an `Item Paket` row
- **AND** the selected product has no active-setting `product_prices.sale_price`
- **THEN** the UI SHALL show an explicit missing-price state for that row
- **AND** the system SHALL NOT silently imply tier pricing was used

#### Scenario: Create form persists informational item price
- **WHEN** a user submits a new bundle with one or more `Item Paket` rows
- **THEN** each row's `Harga Informasi Item` value SHALL be persisted to the new item-level informational price column
- **AND** the system SHALL NOT write that submitted value into the legacy `product_bundle_items.price` column

#### Scenario: Edit form displays saved informational item prices
- **WHEN** a user opens the edit form for an existing bundle
- **THEN** each existing item row SHALL display its saved `Harga Informasi Item` value
- **AND** the user SHALL be able to change and save each value

#### Scenario: Product detail list displays informational item prices
- **WHEN** a user views the bundle list under a product detail page
- **THEN** each bundled item row SHALL display its `Harga Informasi Item` value
- **AND** the displayed item price SHALL be informational only

## ADDED Requirements

### Requirement: Bundle CRUD SHALL configure final bundle sale price
The Product Bundle create and edit surfaces SHALL provide a modifiable `Harga Jual Paket` field backed by a new bundle-level value that represents the final sale price for the parent product and selected bundle combined.

#### Scenario: Create form defaults bundle sale price from parent product
- **WHEN** a user opens `/products/{id}/bundles/create`
- **AND** the parent product has an active-setting `product_prices.sale_price`
- **THEN** the `Harga Jual Paket` field SHALL default to that sale price
- **AND** the user SHALL be able to change the value before saving

#### Scenario: Create form persists bundle sale price
- **WHEN** a user submits the bundle create form with `Harga Jual Paket`
- **THEN** the system SHALL persist that value in the new bundle-level sale price column
- **AND** the system SHALL NOT write that submitted value into the legacy `product_bundles.price` column

#### Scenario: Edit form displays saved bundle sale price
- **WHEN** a user opens the edit form for an existing bundle
- **THEN** the `Harga Jual Paket` field SHALL display the saved new bundle sale price value
- **AND** the user SHALL be able to change and save the value

### Requirement: Product bundle UI SHALL hide legacy bundle price
The Product Bundle create, edit, and product detail list surfaces SHALL hide the legacy `Harga Paket` value backed by `product_bundles.price`.

#### Scenario: Create and edit forms do not show legacy bundle price
- **WHEN** a user opens a Product Bundle create or edit form
- **THEN** the form SHALL NOT show the legacy `Harga Paket` field
- **AND** the form SHALL NOT ask the user to maintain the legacy add-on price

#### Scenario: Product detail list does not show legacy bundle price
- **WHEN** a user views the bundle list under a product detail page
- **THEN** the list SHALL NOT display the legacy `product_bundles.price` value
- **AND** the list SHALL display the new `Harga Jual Paket` value for each bundle instead

### Requirement: Bundle items SHALL configure informational item price
The Product Bundle item table SHALL provide a modifiable `Harga Informasi Item` value for each bundled item, backed by a new item-level column and treated as informational configuration data.

#### Scenario: Selecting an item defaults informational price from selected product
- **WHEN** a user selects a product in an `Item Paket` row
- **AND** the selected product has an active-setting `product_prices.sale_price`
- **THEN** the row's `Harga Informasi Item` field SHALL default to that sale price
- **AND** the user SHALL be able to change the value before saving

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

### Requirement: Product Bundle price configuration SHALL preserve runtime pricing compatibility
This change SHALL preserve existing Sales and POS runtime pricing behavior until those flows are changed by a later proposal.

#### Scenario: Legacy bundle price data remains stored
- **WHEN** the migration and Product Bundle CRUD changes are applied
- **THEN** existing `product_bundles.price` values SHALL remain in the database
- **AND** existing `product_bundle_items.price` values SHALL remain in the database

#### Scenario: Sales and POS behavior remains unchanged
- **WHEN** a bundle is used in Sales or POS before a later Sales/POS pricing change is implemented
- **THEN** those flows SHALL continue using their existing pricing behavior
- **AND** this Product Bundle configuration change SHALL NOT make Sales or POS use `Harga Jual Paket` as the runtime sale price

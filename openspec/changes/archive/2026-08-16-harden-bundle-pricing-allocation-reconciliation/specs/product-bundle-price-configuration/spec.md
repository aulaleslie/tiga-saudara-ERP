## MODIFIED Requirements

### Requirement: Bundle items SHALL configure informational item price
The Product Bundle item table SHALL display a read-only `Harga Informasi Item` for each bundled item, and the server SHALL derive and persist that value from setting-scoped component sale prices rather than trusting a client-submitted price.

#### Scenario: Selecting an item displays the active-setting sale price read-only
- **WHEN** a user selects a component product in a bundle item row
- **AND** the product has an active-setting `product_prices.sale_price`
- **THEN** the row SHALL immediately display that sale price as `Harga Informasi Item`
- **AND** the user SHALL NOT be able to edit the displayed value

#### Scenario: Replicated creation derives each target-setting snapshot
- **WHEN** bundle creation generates independent copies for multiple settings
- **AND** a component has different `product_prices.sale_price` values in those settings
- **THEN** each bundle copy SHALL persist the component price belonging to that copy's setting
- **AND** the system SHALL NOT copy the active setting's price into a target setting that has its own price

#### Scenario: Missing target-setting price uses active-setting fallback
- **WHEN** a replicated target setting has no `ProductPrice` row for a component
- **AND** the component has an active-setting `product_prices.sale_price`
- **THEN** that target bundle copy SHALL persist the active-setting sale price as its informational-price snapshot

#### Scenario: Missing target and active-setting prices reject creation
- **WHEN** neither the target setting nor the active setting has a component sale price
- **THEN** bundle creation SHALL fail validation atomically
- **AND** no partial set of setting copies SHALL remain persisted

#### Scenario: Tampered create request cannot override derived prices
- **WHEN** a client submits an informational item price different from the server-resolved setting price
- **THEN** the server SHALL ignore or reject the submitted price
- **AND** every persisted copy SHALL contain its server-derived snapshot

#### Scenario: Editing refreshes only the selected setting copy
- **WHEN** an administrator saves one setting's existing bundle copy
- **THEN** every component informational-price snapshot in that copy SHALL be refreshed from that copy's current setting price with the active-setting fallback
- **AND** other setting copies SHALL remain unchanged

#### Scenario: Saved snapshots remain stable between administrative saves
- **WHEN** a component product sale price changes after a bundle copy was saved
- **THEN** the bundle copy's informational-price snapshot SHALL remain unchanged
- **AND** the snapshot SHALL change only when an administrator saves that bundle copy again

#### Scenario: Product detail displays saved informational snapshots
- **WHEN** a user views a bundle under a product detail page
- **THEN** each component SHALL display the saved informational-price snapshot for that bundle copy
- **AND** the displayed value SHALL remain informational rather than customer-billable

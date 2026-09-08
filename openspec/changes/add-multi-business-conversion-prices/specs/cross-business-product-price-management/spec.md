## ADDED Requirements

### Requirement: Page separates base and conversion prices
The authorized multi-business price page SHALL retain its existing table under Harga Satuan Dasar with the base unit and SHALL display Harga Satuan Konversi with every business as a row and every product conversion as a column labeled with unit and factor relative to the base unit. Unit/factor metadata SHALL be read-only on this page.

#### Scenario: Product has conversion prices
- **WHEN** the page loads a product with KOTAK equal to 12 PCS
- **THEN** it SHALL show a KOTAK · 12 PCS column with each business's independently stored conversion price
- **AND** the base section SHALL retain its existing fields including read-only average purchase price

#### Scenario: Product has no conversions
- **WHEN** the product has no conversions
- **THEN** the conversion section SHALL show an empty-state message
- **AND** base prices SHALL remain editable and savable

#### Scenario: Unauthorized access
- **WHEN** a user lacks products.manage_cross_business_prices
- **THEN** the page and update endpoint SHALL deny access to conversion prices under the existing permission

### Requirement: Conversion fields follow page editing and decimal behavior
Conversion prices SHALL start read-only, become editable through Ubah, and support non-negative values with at most two decimal places. Batal SHALL restore original values and missing states. Validation feedback SHALL preserve submitted values with the correct business/conversion identities.

#### Scenario: Decimal value remains stable
- **WHEN** a loaded conversion price of 22500.50 is displayed, submitted unchanged, or restored by Cancel
- **THEN** its numeric value SHALL remain 22500.50 and its localized display SHALL be 22.500,50

#### Scenario: Invalid conversion input
- **WHEN** a submitted price is negative, nonnumeric, or has more than two decimal places
- **THEN** the system SHALL reject the save without modifying either section

### Requirement: Missing conversion prices are distinct from zero
Missing conversion-price rows SHALL display Belum diatur and use blank edit values. Leaving an originally missing cell blank SHALL preserve its absence; supplying a valid price SHALL create that business/conversion row. Existing rows SHALL require a numeric price and SHALL NOT be deleted by clearing a field.

#### Scenario: Missing cell stays missing
- **WHEN** a user saves another price while leaving an originally missing conversion cell blank
- **THEN** the system SHALL NOT create that missing conversion-price row

#### Scenario: Explicit zero creates a row
- **WHEN** a user enters zero into a missing conversion cell and saves
- **THEN** the system SHALL create a zero-priced row with the existing enabled defaults

### Requirement: Combined price updates are atomic and preserve metadata
The system SHALL save base and conversion prices in one transaction, retaining existing base-price protections. Updating a conversion price SHALL preserve its sales_enabled and purchase_enabled flags and shared definition. Payload identities SHALL match the current product's complete business/conversion matrix with no duplicates or foreign IDs.

#### Scenario: Independent business prices
- **WHEN** a user changes one business's conversion price and leaves the others unchanged
- **THEN** only that conversion cell's numeric price SHALL change
- **AND** existing flags, units, factors, average purchase prices, and tax assignments SHALL remain unchanged

#### Scenario: Any save fails
- **WHEN** validation, identity verification, conflict detection, or persistence fails for either section
- **THEN** neither section SHALL be partially saved

#### Scenario: Forged or incomplete matrix
- **WHEN** a payload contains a duplicate, missing, or foreign business/conversion identity
- **THEN** the system SHALL reject the entire save

### Requirement: Combined save rejects stale conversion state
The system SHALL validate trusted loaded-state evidence against current business membership, conversion membership, units, factors, conversion price values, row presence, and versions before saving. Concurrent structure mutations and combined saves SHALL be coordinated so the validation remains valid through commit.

#### Scenario: Shared factor changed after page load
- **WHEN** another request changes KOTAK from 12 to 24 PCS after the page loads
- **THEN** saving the stale page SHALL reject both sections and instruct the user to reload

#### Scenario: Conversion membership or prices changed
- **WHEN** a conversion is added or deleted, a business is added or deleted, or a conversion-price row is created, changed, or deleted after loading
- **THEN** the stale save SHALL be rejected without overwriting current data

#### Scenario: Snapshot was altered
- **WHEN** a client modifies loaded-state evidence or reuses evidence for another product
- **THEN** the save SHALL be rejected

### Requirement: Shared conversion lifecycle remains global with local existing-price edits
Product edit SHALL continue using shared unit/factor definitions across all businesses. Updating an existing conversion SHALL update its unit/factor globally and its submitted price only for the active business. Other businesses' prices SHALL NOT be recalculated after a factor change. New conversions SHALL seed the initial price to all existing businesses, and deletion SHALL remove the shared conversion for all businesses.

#### Scenario: Existing factor changes
- **WHEN** business A changes an existing conversion from 12 to 24 PCS and edits its price
- **THEN** all businesses SHALL use factor 24
- **AND** other businesses SHALL retain their previous numeric prices

#### Scenario: New conversion is created
- **WHEN** business A adds a conversion priced at 22500
- **THEN** all existing businesses SHALL share that conversion and receive its initial price of 22500

#### Scenario: Shared conversion is deleted
- **WHEN** product edit deletes a conversion
- **THEN** that conversion SHALL no longer be available to any business

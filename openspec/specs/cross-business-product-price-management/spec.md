# cross-business-product-price-management Specification

## Purpose
TBD - created by archiving change manage-cross-business-product-prices. Update Purpose after archive.
## Requirements
### Requirement: Authorized users can open cross-business product price management
The system SHALL register a dedicated product permission for cross-business price management. Only a user granted that permission SHALL see the corresponding action on the product list or access the management page and its save operation.

#### Scenario: Authorized user sees and opens the action
- **WHEN** a user with the cross-business product price-management permission views the product list
- **THEN** the system SHALL show an action for the selected product
- **AND** the action SHALL open that product's cross-business price-management page

#### Scenario: Unauthorized user cannot access price management
- **WHEN** a user without the cross-business product price-management permission requests the action, page, or save operation
- **THEN** the system SHALL deny access
- **AND** the system SHALL NOT disclose cross-business price data

### Requirement: The page displays every business price in a safe view state
The cross-business price-management page SHALL list every business setting for the selected product. It SHALL initially display all price values read-only and SHALL provide a control to return to the product list.

#### Scenario: Existing business price row is displayed
- **WHEN** a price row exists for the selected product and a business setting
- **THEN** the page SHALL display that setting's sales price, tier 1 price, tier 2 price, last purchase price, and average purchase price
- **AND** all values SHALL be read-only initially

#### Scenario: Missing business price row defaults to zero
- **WHEN** no price row exists for the selected product and a listed business setting
- **THEN** the page SHALL display zero for sales price, tier 1 price, tier 2 price, last purchase price, and average purchase price

#### Scenario: User returns to the product list
- **WHEN** the user activates the Back control from the cross-business price-management page
- **THEN** the system SHALL navigate to the product list

### Requirement: Page-level editing limits changes to commercial prices
The page SHALL enter edit mode only after the user activates `Ubah`. In edit mode, sales price, tier 1 price, tier 2 price, and last purchase price SHALL be editable. Average purchase price SHALL remain read-only in all states.

#### Scenario: User enters edit mode
- **WHEN** the user activates `Ubah`
- **THEN** the system SHALL make sales price, tier 1 price, tier 2 price, and last purchase price editable for every listed business
- **AND** the system SHALL continue displaying average purchase price as read-only

#### Scenario: User cancels edit mode
- **WHEN** the user activates `Batal` before saving
- **THEN** the system SHALL discard unsaved price inputs
- **AND** the system SHALL restore the page's read-only state using the loaded values

### Requirement: Bulk save is atomic and preserves purchase average and tax metadata
The system SHALL validate and save all editable business prices in one transaction. It SHALL update existing rows or create missing rows, without modifying any existing average purchase price or tax assignment.

#### Scenario: Valid bulk save updates every existing row
- **WHEN** the user submits valid non-negative values for all editable prices
- **AND** all listed price rows remain current
- **THEN** the system SHALL update sales price, tier 1 price, tier 2 price, and last purchase price for every existing listed row in one transaction
- **AND** the system SHALL preserve each row's average purchase price, purchase tax ID, and sales tax ID
- **AND** the page SHALL return to read-only state with the saved values

#### Scenario: Valid bulk save creates a missing row
- **WHEN** the user submits valid editable prices for a business that had no product price row
- **THEN** the system SHALL create one row for that product and business
- **AND** the new row SHALL store the submitted sales, tier 1, tier 2, and last purchase prices
- **AND** the new row SHALL set average purchase price to zero and tax IDs to null

#### Scenario: Invalid input leaves every row unchanged
- **WHEN** any submitted editable price is absent, non-numeric, or less than zero
- **THEN** the system SHALL reject the save with validation feedback
- **AND** the system SHALL NOT create or modify any listed price row

### Requirement: Bulk save rejects stale data and duplicate interaction safely
The system SHALL prevent a loaded page from overwriting prices saved by another request and SHALL prevent duplicate user submission while a save is pending.

#### Scenario: A row changed after page load
- **WHEN** any existing product price row has changed since the user loaded the management page
- **THEN** the system SHALL reject the entire bulk save
- **AND** the system SHALL NOT apply changes to any other business row
- **AND** the system SHALL instruct the user to reload the page

#### Scenario: User double-clicks Save
- **WHEN** the user activates Save more than once while the first save is pending
- **THEN** the interface SHALL disable subsequent Save interactions until the request finishes
- **AND** the persisted result SHALL remain equivalent to a single valid save


### Requirement: Currency formatting preserves product price magnitude
The cross-business price-management page SHALL normalize decimal-backed price values to its zero-decimal Rupiah input representation before applying locale formatting. Formatting, editing, cancellation, validation restoration, and unchanged submission MUST NOT multiply or otherwise change a price's numeric magnitude.

#### Scenario: Two-decimal database value is displayed at the correct magnitude
- **WHEN** a business product price field contains the decimal-backed value `2500000.00`
- **THEN** the page SHALL display the value as `2.500.000` under Indonesian zero-decimal formatting
- **AND** the page SHALL NOT display the value as `250.000.000`

#### Scenario: Every displayed price field uses consistent normalization
- **WHEN** the page loads sales price, tier 1 price, tier 2 price, last purchase price, and average purchase price values
- **THEN** each value SHALL be normalized before the zero-decimal currency mask is applied
- **AND** each displayed value SHALL retain the magnitude of its stored numeric value

#### Scenario: Cancel restores the loaded magnitude
- **WHEN** a user edits a commercial price and then activates `Batal`
- **THEN** the page SHALL restore the originally loaded zero-decimal value
- **AND** reapplying the currency mask SHALL NOT change that value's magnitude

#### Scenario: Unchanged submission preserves the price
- **WHEN** a user enters edit mode and submits a loaded `2500000.00` price without changing it
- **THEN** the system SHALL persist a price numerically equal to `2500000.00`
- **AND** the saved value SHALL NOT become `250000000.00`

#### Scenario: Fractional Rupiah value follows zero-decimal precision
- **WHEN** a stored or validation-restored price contains a fractional component
- **THEN** the page SHALL round it to the nearest whole Rupiah before applying the zero-decimal mask
- **AND** the resulting value SHALL remain stable through edit and cancel interactions

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

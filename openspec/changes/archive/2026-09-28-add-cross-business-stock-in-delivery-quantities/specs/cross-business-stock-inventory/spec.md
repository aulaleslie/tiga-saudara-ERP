# Spec Delta

## ADDED Requirements

### Requirement: System SHALL display outstanding approved purchase quantities globally and per business
For each product row, the report SHALL display `Dalam Pengiriman` as the outstanding quantity from non-archived purchases belonging to the currently selected and visible businesses whose status is `APPROVED` or `RECEIVED PARTIALLY`. For each purchase detail, outstanding quantity SHALL equal its ordered base-unit quantity minus the sum received through approved receiving notes, clamped to zero before aggregation. The global value SHALL sum the per-business values across the currently selected businesses. In-delivery quantity SHALL remain informational, SHALL NOT be added to Good or Bad stock, and SHALL NOT affect the availability filter.

#### Scenario: Approved purchase has not been received
- **WHEN** a selected business has an approved non-archived purchase detail for 10 base units and no approved receiving-note quantity
- **THEN** that business's `Dalam Pengiriman` value is 10
- **AND** the global value includes those 10 units

#### Scenario: Purchase is partially received
- **WHEN** a selected business has a `RECEIVED PARTIALLY` purchase detail for 10 base units and approved receiving notes total 3 base units
- **THEN** that business's `Dalam Pengiriman` value is 7

#### Scenario: Unapproved receiving note does not reduce the projection
- **WHEN** an eligible purchase detail has a receiving-note quantity whose parent receiving note is not approved
- **THEN** that quantity is not subtracted from `Dalam Pengiriman`

#### Scenario: Ineligible purchase is excluded
- **WHEN** a purchase is archived or has a status other than `APPROVED` or `RECEIVED PARTIALLY`
- **THEN** its details contribute zero to `Dalam Pengiriman`

#### Scenario: Received quantity exceeds ordered quantity
- **WHEN** approved receiving-note quantities for a purchase detail exceed its ordered quantity
- **THEN** that detail contributes zero rather than a negative quantity

#### Scenario: Business selection changes the global quantity
- **WHEN** the user deselects a visible business
- **THEN** its outstanding purchase quantities disappear from the report
- **AND** the global `Dalam Pengiriman` value is recalculated from the remaining selected businesses

#### Scenario: In-delivery quantity does not imply stock availability
- **WHEN** a product has zero Good and Bad stock but has a positive `Dalam Pengiriman` quantity
- **THEN** the stock values remain zero
- **AND** the existing availability filter continues to classify the product from Good and Bad stock only

### Requirement: System SHALL present in-delivery quantities at global and business scope only
The report SHALL place one global `Dalam Pengiriman` column alongside the global Good and Bad totals and one `Dalam Pengiriman` column in each selected business group. A business's in-delivery value SHALL remain visible and unchanged when that business is expanded to locations; the report SHALL NOT allocate, repeat, or infer in-delivery quantities per location.

#### Scenario: Business is collapsed
- **WHEN** a selected business is shown in collapsed form
- **THEN** its group shows Good, Bad, and `Dalam Pengiriman` quantities

#### Scenario: Business is expanded
- **WHEN** the user expands a business into its location-level Good and Bad columns
- **THEN** the business-level `Dalam Pengiriman` quantity remains present once in that business group
- **AND** no location receives an in-delivery column or inferred share

#### Scenario: Excel export contains the same scopes
- **WHEN** the report is exported
- **THEN** the export contains one global `Dalam Pengiriman` column and one per selected business
- **AND** its always-expanded location sections contain only their existing Good and Bad quantities

### Requirement: System SHALL provide consistent decimal and conversion quantity display modes
The report SHALL provide a report-wide selector between `Hanya Angka` and `Dengan Satuan`. The selected mode SHALL apply to every existing and new quantity column on screen and in the export, including global, business, and location Good and Bad quantities and global and business in-delivery quantities. Changing display mode SHALL NOT change stored or aggregated quantity values.

#### Scenario: Decimal mode formats a whole quantity
- **WHEN** a quantity is mathematically equal to 10 in `Hanya Angka` mode
- **THEN** it is displayed as `10` without `,00`

#### Scenario: Decimal mode formats a fractional quantity
- **WHEN** a quantity is 10.5 in `Hanya Angka` mode
- **THEN** it is displayed as `10,50`

#### Scenario: Decimal mode rounds display precision
- **WHEN** a quantity is 10.126 in `Hanya Angka` mode
- **THEN** it is displayed as `10,13`
- **AND** the underlying aggregate is not rounded to that display value

#### Scenario: Conversion mode uses the largest configured conversion
- **WHEN** a product's base unit is `Pcs`, its largest conversion is `Karton` with factor 144, and a quantity is 150
- **THEN** `Dengan Satuan` mode displays `1 Karton 6 Pcs`

#### Scenario: Conversion mode preserves a fractional remainder
- **WHEN** the same product has a quantity of 150.5
- **THEN** `Dengan Satuan` mode displays `1 Karton 6,50 Pcs`

#### Scenario: Conversion mode has no configured conversion
- **WHEN** a product has base unit `Pcs`, no configured conversion, and quantity 17.5
- **THEN** `Dengan Satuan` mode displays `17,50 Pcs`

#### Scenario: Conversion mode has no base unit
- **WHEN** a product has no base unit and quantity 17.5
- **THEN** `Dengan Satuan` mode displays `17,50`

#### Scenario: Decimal export preserves numeric cells
- **WHEN** the report is exported in `Hanya Angka` mode
- **THEN** quantity cells remain numeric spreadsheet values with display precision of at most two decimal places and no displayed decimal places for whole values

#### Scenario: Conversion export uses denominated text
- **WHEN** the report is exported in `Dengan Satuan` mode
- **THEN** quantity cells contain the same unit-denominated text as the corresponding report quantities

### Requirement: System SHALL load report quantities with bounded query growth
Rendering a paginated report SHALL bulk-load stock, in-delivery aggregates, and unit metadata for the page's products and selected businesses. Database queries SHALL NOT execute once per product, business, location, purchase detail, or quantity cell. Changing only the quantity display mode SHALL reuse already-loaded aggregate data rather than issuing stock or purchase aggregation queries solely to recalculate formatting. Export processing SHALL use bounded batches so its working set does not grow by loading all related stock, purchase-detail, receiving-note, and conversion rows simultaneously.

#### Scenario: Page contains additional products and businesses
- **WHEN** the number of products on a page or selected businesses increases
- **THEN** the report uses bulk aggregation without introducing per-product, per-business, per-location, or per-cell database queries

#### Scenario: User changes quantity display mode
- **WHEN** the user switches between `Hanya Angka` and `Dengan Satuan` without changing report filters or pagination
- **THEN** the displayed quantities are reformatted from the same aggregate values
- **AND** no stock or purchase aggregation query is issued solely because of the mode change

#### Scenario: Large filtered result is exported
- **WHEN** an export contains more products than one processing batch
- **THEN** all matching rows are exported
- **AND** related quantities and unit metadata are loaded and released in bounded batches


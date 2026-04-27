## ADDED Requirements

### Requirement: POS SHALL require bundle selection for bundle-parent products before adding a bundled line
The POS sell flow SHALL detect when a selected product is a bundle parent and MUST present bundle options before creating a bundled cart line. The cashier MUST be able to select an available bundle or explicitly continue without a bundle.

#### Scenario: Cashier selects a bundle for a bundle-parent product
- **WHEN** the cashier selects a product whose POS search result indicates it is a bundle parent
- **THEN** the POS shell fetches the available bundles for that parent product
- **AND** the cashier can choose one bundle before the cart line is created

#### Scenario: Cashier continues without a bundle
- **WHEN** the cashier selects a product whose POS search result indicates it is a bundle parent and chooses to continue without a bundle
- **THEN** the POS shell creates a normal parent product cart line
- **AND** the resulting cart line records that bundle selection was explicitly skipped

#### Scenario: Non-bundle product add remains unchanged
- **WHEN** the cashier selects a product that is not a bundle parent
- **THEN** the POS shell adds the product through the existing normal cart flow without showing bundle selection UI

### Requirement: POS cart snapshots SHALL preserve bundle-aware line metadata
When a POS cart line is created with a selected bundle, the cart snapshot SHALL keep the parent line as a single visible line and MUST include normalized bundle metadata and bundled child item snapshots on that line. The selected bundled parent row unit price SHALL be the bundle's final sale price, while bundled child item prices remain internal non-billable allocation metadata.

#### Scenario: Snapshot includes selected bundle metadata
- **WHEN** a cashier adds a parent product with a selected bundle
- **THEN** the returned cart snapshot line includes the selected bundle identifier and bundle selection mode
- **AND** the line includes the selected bundle name, bundle sale price context, and bundled child item snapshots

#### Scenario: Snapshot includes bundled child product attributes needed for checkout
- **WHEN** a cashier adds a parent product with a selected bundle
- **THEN** each bundled child item snapshot includes its bundle item identifier, product identifier, quantity-per-bundle, and display name
- **AND** each bundled child item snapshot includes the child's `stock_managed` and serial-tracking attributes

#### Scenario: Snapshot keeps component allocation metadata non-display
- **WHEN** a cashier adds a parent product with a selected bundle whose child items have informational prices
- **THEN** POS may carry those informational prices as internal allocation metadata
- **AND** the child informational prices SHALL NOT be treated as billable cart line prices or component line totals

#### Scenario: Merge behavior distinguishes bundle selections
- **WHEN** the cart contains the same parent product added once without a bundle and once with a selected bundle, or with two different bundle selections
- **THEN** the POS cart keeps those as distinct cart lines
- **AND** the lines MUST NOT merge across different bundle selection states

### Requirement: POS checkout SHALL persist bundle-aware sales composition
When a POS cart line carries a selected bundle, checkout finalization SHALL persist the parent sale detail and the bundled child item records so the resulting sale preserves the selected bundle composition. POS checkout SHALL keep bundled child item records non-billable even when their informational prices are used internally for owner split allocation.

#### Scenario: Checkout persists parent and bundled child records
- **WHEN** a POS checkout finalizes a cart that contains a parent line with a selected bundle
- **THEN** the posted sale includes the parent sale detail for the selected product
- **AND** the sale persists bundled child item records associated with that parent line

#### Scenario: Checkout persists child records as non-billable context
- **WHEN** POS checkout finalizes a selected bundle whose child items have informational allocation prices
- **THEN** the sale persists bundled child item records associated with the parent line
- **AND** those child records SHALL NOT carry billable `price` or `sub_total` amounts

#### Scenario: Checkout without bundle remains unchanged
- **WHEN** a POS checkout finalizes a cart line whose bundle selection mode is skipped or none
- **THEN** the checkout posts the line as a normal POS sale detail without bundled child records

### Requirement: POS checkout SHALL apply stock validation and deduction per product based on stock-managed behavior
When a selected bundle is posted through POS checkout, the parent product and each bundled child product SHALL independently follow stock validation and deduction rules based on that product's `stock_managed` flag.

#### Scenario: Stock-managed parent and child products both deduct stock
- **WHEN** POS checkout finalizes a selected bundle whose parent product and bundled child product both have `stock_managed = true`
- **THEN** checkout validates stock sufficiency for the parent product and the bundled child product
- **AND** checkout deducts stock for both products

#### Scenario: Non-stock-managed bundled child skips stock deduction
- **WHEN** POS checkout finalizes a selected bundle containing a bundled child product with `stock_managed = false`
- **THEN** checkout MUST NOT require stock availability for that bundled child product
- **AND** checkout MUST NOT create stock deduction for that bundled child product

#### Scenario: Non-stock-managed parent skips stock deduction while stock-managed child deducts stock
- **WHEN** POS checkout finalizes a selected bundle whose parent product has `stock_managed = false` and a bundled child product has `stock_managed = true`
- **THEN** checkout skips stock validation and deduction for the parent product
- **AND** checkout validates stock sufficiency and deducts stock for the bundled child product
### Requirement: Bundle parent add flows SHALL collect bundle intent before cart-line targeting
The POS sell flow SHALL require every add action for a bundle-parent product to collect bundle intent before choosing an existing cart row or creating a new row. Bundle intent MUST be one of: selected bundle id, or explicit no-bundle continuation. This requirement applies to serial-tracked and non-serial products.

#### Scenario: Serial scan asks bundle choice before appending to existing row
- **WHEN** the cashier scans a serial number for a product that is a bundle parent and the cart already contains a row for the same parent product with a selected bundle
- **THEN** the POS shell MUST present bundle selection or explicit no-bundle continuation before appending the scanned serial
- **AND** the scanned serial MUST be appended only to the row matching the cashier's chosen bundle intent

#### Scenario: Non-serial scan asks bundle choice before incrementing existing row
- **WHEN** the cashier scans or adds a non-serial product that is a bundle parent and the cart already contains a row for the same parent product with a selected bundle
- **THEN** the POS shell MUST present bundle selection or explicit no-bundle continuation before incrementing quantity
- **AND** quantity MUST increase only on the row matching the cashier's chosen bundle intent

#### Scenario: Different bundle choice creates or targets different row
- **WHEN** the cart contains Product A with Bundle A and the cashier adds Product A again but chooses Bundle B
- **THEN** the POS cart MUST keep Product A with Bundle B separate from Product A with Bundle A

#### Scenario: No-bundle choice creates or targets normal row
- **WHEN** the cart contains Product A with a selected bundle and the cashier adds Product A again but chooses to continue without a bundle
- **THEN** the POS cart MUST create or target a normal Product A row without selected bundle metadata
- **AND** the normal row MUST NOT merge into the selected-bundle row

### Requirement: POS bundled checkout SHALL deduct child stock once per sold bundle unit
When POS checkout finalizes a selected bundle with stock-managed child products, the system SHALL deduct bundle child stock according to the sold parent bundle quantity. Split posting MUST NOT cause bundle child stock to be deducted more times than the number of sold bundle units requires.

#### Scenario: Single bundled serial parent deducts one child unit
- **WHEN** POS checkout finalizes one stock-managed serial-tracked parent product with one selected bundle child quantity of one
- **THEN** the parent product stock is deducted by one
- **AND** the bundle child product stock is deducted by one

#### Scenario: Two bundled serial parents split by source deduct two child units total
- **WHEN** POS checkout finalizes two serial-tracked parent units in one bundled cart line and the assigned parent serials resolve into two split groups
- **THEN** the parent product stock is deducted by two across the parent source groups
- **AND** the bundle child product stock is deducted by two total across all posted groups
- **AND** the bundle child product stock MUST NOT be deducted once per split group using the full original child quantity

### Requirement: POS bundled checkout SHALL retain child source allocation ownership
When bundle child stock is allocated from a source location, the final stock movement for that child product SHALL use the source location, source setting, and tax bucket selected by the stock resolver.

#### Scenario: Child stock source remains resolver-selected during split posting
- **WHEN** a bundled checkout line is split by the parent product source but the bundle child product is allocated from a separate source location
- **THEN** the child product stock movement uses the child allocation source location and source setting
- **AND** the child product stock bucket decremented matches the child allocation `tax_bucket_used` value


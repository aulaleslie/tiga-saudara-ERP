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
When a POS cart line is created with a selected bundle, the cart snapshot SHALL keep the parent line as a single visible line and MUST include normalized bundle metadata and bundled child item snapshots on that line.

#### Scenario: Snapshot includes selected bundle metadata
- **WHEN** a cashier adds a parent product with a selected bundle
- **THEN** the returned cart snapshot line includes the selected bundle identifier and bundle selection mode
- **AND** the line includes the selected bundle name, bundle price, and bundled child item snapshots

#### Scenario: Snapshot includes bundled child product attributes needed for checkout
- **WHEN** a cashier adds a parent product with a selected bundle
- **THEN** each bundled child item snapshot includes its bundle item identifier, product identifier, quantity-per-bundle, and display name
- **AND** each bundled child item snapshot includes the child's `stock_managed` and serial-tracking attributes

#### Scenario: Merge behavior distinguishes bundle selections
- **WHEN** the cart contains the same parent product added once without a bundle and once with a selected bundle, or with two different bundle selections
- **THEN** the POS cart keeps those as distinct cart lines
- **AND** the lines MUST NOT merge across different bundle selection states

### Requirement: POS checkout SHALL persist bundle-aware sales composition
When a POS cart line carries a selected bundle, checkout finalization SHALL persist the parent sale detail and the bundled child item records so the resulting sale preserves the selected bundle composition.

#### Scenario: Checkout persists parent and bundled child records
- **WHEN** a POS checkout finalizes a cart that contains a parent line with a selected bundle
- **THEN** the posted sale includes the parent sale detail for the selected product
- **AND** the sale persists bundled child item records associated with that parent line

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

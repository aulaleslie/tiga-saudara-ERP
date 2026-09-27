## MODIFIED Requirements

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

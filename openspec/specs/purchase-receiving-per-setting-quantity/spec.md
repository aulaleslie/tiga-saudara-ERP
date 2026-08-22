## Purpose
Purchase receiving approval records transaction quantities at the setting level (not global) and excludes consignment-classified locations from ordinary receiving.

## Requirements

### Requirement: Ordinary Purchase receiving excludes consignment locations
The system SHALL prevent ordinary Purchase Receiving Notes from being created or approved for a location classified as consignment while preserving all existing ordinary receiving behavior for standard locations.

#### Scenario: Ordinary receiving selects locations
- **WHEN** an authorized user opens ordinary Purchase receiving
- **THEN** the location selector SHALL exclude locations where `is_consignment = true`

#### Scenario: Forged consignment location is submitted
- **WHEN** a request submits a consignment location for ordinary Purchase receiving
- **THEN** the system SHALL reject the request without creating a Received Note or changing stock

#### Scenario: Location is reclassified before approval
- **WHEN** a pending ordinary Received Note targets a location that becomes consignment-classified before approval
- **THEN** approval SHALL revalidate and reject the receiving without stock mutation

#### Scenario: Standard location behavior is preserved
- **WHEN** an ordinary Purchase receiving targets an eligible standard location
- **THEN** its existing lifecycle, per-setting quantities, stock, serial, cost, notification, and Purchase completion behavior SHALL remain unchanged

### Requirement: Transaction log records per-setting quantity on purchase receiving approval

When a purchase receiving is approved, the transaction log SHALL record `previous_quantity`, `after_quantity`, and `current_quantity` as the sum of `product_stocks.quantity` across all locations belonging to the purchase's `setting_id`, not the global `product.product_quantity`.

#### Scenario: Single-location setting receives stock

- **WHEN** a purchase belonging to Setting A (which has 1 location) is approved for receiving
- **AND** the product has 50 units in Setting A's location and 30 units in Setting B's location (80 global)
- **AND** the received quantity is 10
- **THEN** the transaction SHALL record `previous_quantity = 50`
- **AND** the transaction SHALL record `after_quantity = 60`
- **AND** the transaction SHALL record `current_quantity = 60`

#### Scenario: Multi-location setting receives stock

- **WHEN** a purchase belonging to Setting A (which has 2 locations: L1 with 30 units, L2 with 20 units) is approved for receiving into L1
- **AND** the received quantity is 5
- **THEN** the transaction SHALL record `previous_quantity = 50` (sum of L1 + L2 = 30 + 20)
- **AND** the transaction SHALL record `after_quantity = 55` (50 + 5)
- **AND** the transaction SHALL record `current_quantity = 55`

#### Scenario: Product has no prior stock in the setting

- **WHEN** a purchase belonging to Setting A is approved for receiving
- **AND** the product has 0 units across all Setting A locations (but may have stock in other settings)
- **AND** the received quantity is 15
- **THEN** the transaction SHALL record `previous_quantity = 0`
- **AND** the transaction SHALL record `after_quantity = 15`
- **AND** the transaction SHALL record `current_quantity = 15`

### Requirement: Per-location quantity fields remain unchanged

The `previous_quantity_at_location` and `after_quantity_at_location` fields SHALL continue to reflect the stock at the specific receiving location (from `product_stocks.quantity` for the receiving location).

#### Scenario: Per-location fields use receiving location stock

- **WHEN** a purchase receiving is approved for location L1
- **AND** L1 has 30 units before receiving and receives 5 units
- **THEN** the transaction SHALL record `previous_quantity_at_location = 30`
- **AND** the transaction SHALL record `after_quantity_at_location = 35`

### Requirement: Global product quantity increment is unaffected

The `product.product_quantity` global counter SHALL continue to be incremented by the received quantity, independent of the per-setting transaction log values.

#### Scenario: Global quantity increments normally

- **WHEN** a purchase receiving is approved with 10 units
- **AND** the global `product.product_quantity` is 80 before approval
- **THEN** `product.product_quantity` SHALL be 90 after approval
- **AND** this global value SHALL NOT be used in the transaction `previous_quantity` or `after_quantity` fields

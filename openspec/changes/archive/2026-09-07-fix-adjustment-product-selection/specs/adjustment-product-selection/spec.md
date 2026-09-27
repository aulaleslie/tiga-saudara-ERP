## ADDED Requirements

### Requirement: Adjustment forms receive product selections
The system SHALL deliver selections from the embedded product search to the adjustment product table on adjustment create and edit forms. With a selected location and a product not already present, selection SHALL add exactly one row using the existing table initialization behavior.

#### Scenario: Select a product on adjustment create
- **GIVEN** an authorized user has selected a location on `/adjustments/create`
- **WHEN** the user selects a stock-managed product from the search results
- **THEN** the adjustment table displays one row for that product with its identity, unit, and location-specific stock values

#### Scenario: Add a product while editing an adjustment
- **GIVEN** an adjustment edit form has a selected location and existing product rows
- **WHEN** the user selects another product from the search results
- **THEN** the adjustment table adds that product once and retains the existing rows

### Requirement: Breakage forms receive product selections
The system SHALL deliver selections from the embedded product search to the breakage product table on breakage create and edit forms, preserving existing breakage row initialization.

#### Scenario: Select a product on breakage create
- **GIVEN** an authorized user has selected a location on the breakage create form
- **WHEN** the user selects a product not already in the table
- **THEN** the breakage table displays that product once with its existing initial adjustment quantities

#### Scenario: Add a product while editing breakage
- **GIVEN** a breakage edit form has a selected location and existing rows
- **WHEN** the user selects another product
- **THEN** the breakage table adds the product once and retains the existing rows

### Requirement: Selection routing preserves purchase compatibility
The reused search component SHALL default to the purchase product cart when no recipient is supplied. An explicitly configured recipient SHALL receive the existing `productSelected` payload without broadcasting it to unrelated component types. Recipient configuration SHALL persist across Livewire requests and SHALL NOT be writable by the client.

#### Scenario: Existing purchase caller omits the recipient
- **WHEN** a user selects a product through a purchase search with default configuration
- **THEN** the selection targets the purchase cart with the original product payload

#### Scenario: Adjustment recipient persists across requests
- **GIVEN** the search was mounted with an adjustment or breakage table recipient
- **WHEN** the user selects a product on a subsequent Livewire request
- **THEN** the event targets the configured table and preserves the product identity, serial requirement, and other original payload fields
- **AND** it is not dispatched to the purchase cart

#### Scenario: Client attempts to change the recipient
- **WHEN** a client attempts to update the mounted recipient property
- **THEN** Livewire rejects the property update and does not redirect product selection

### Requirement: Existing selection guards remain effective
Adjustment and breakage tables SHALL retain their location prerequisite and duplicate-product protection when receiving the corrected selection events.

#### Scenario: Location has not been selected
- **WHEN** a user selects a product on an adjustment or breakage create form without a selected location
- **THEN** no product row is added
- **AND** the existing select-location feedback is shown

#### Scenario: Product is already selected
- **GIVEN** a product already exists in the adjustment or breakage table
- **WHEN** the user selects it again
- **THEN** no duplicate row is added
- **AND** the existing duplicate-product feedback is shown

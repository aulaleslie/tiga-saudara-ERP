# adjustment-product-selection Specification

## Purpose
Ensure product searches on adjustment and breakage forms deliver selections to their respective tables while preserving purchase compatibility and existing selection guards.

## Requirements

### Requirement: Adjustment forms receive product selections
The system SHALL deliver selections from the adjustment product-search dialog to the adjustment editor on create and edit forms. With a selected location and a product not already present, selection SHALL add exactly one row with zero proposed good and bad counts, product identity, base unit, and separate location-specific existing-stock comparison. Serial requirements SHALL be loaded authoritatively rather than relying on optional browser payload keys.

#### Scenario: Select a product on adjustment create
- **GIVEN** an authorized user has selected a location on `/adjustments/create`
- **WHEN** the user selects a stock-managed product from dialog search results
- **THEN** the editor displays one zero-count row with identity, unit, and existing-stock comparison without an undefined serial flag error

#### Scenario: Add a product while editing an adjustment
- **GIVEN** an adjustment edit form has a selected location and existing product rows
- **WHEN** the user selects another product from the search dialog
- **THEN** the editor adds that product once at zero and retains existing proposed counts and serials

### Requirement: Breakage forms receive product selections
The system SHALL provide breakage create and edit forms with an internal product-search dialog and unified scan input that deliver selected active stock-managed products to the breakage product table. With a selected authorized standard location, a product not already present SHALL create exactly one zero-breakage row with authoritative identity, base unit, serial requirement, and selected-location availability. Selecting an existing product SHALL focus or identify its row without resetting quantity or serial state.

#### Scenario: Select a product on breakage create
- **GIVEN** an authorized user has selected a standard location on the breakage create form
- **WHEN** the user selects a stock-managed product from product search
- **THEN** the breakage table displays that product once with zero requested breakage and location-specific availability

#### Scenario: Add a product while editing breakage
- **GIVEN** a pending breakage edit form has a selected location and existing rows
- **WHEN** the user selects another product
- **THEN** the breakage table adds that product once and retains all existing quantities and serial selections

#### Scenario: Scan a product into breakage
- **GIVEN** an authorized standard location is selected
- **WHEN** the unified scanner resolves a product, supported conversion, or eligible serial
- **THEN** the result is delivered only to the breakage table and follows the breakage quantity rules

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
Adjustment and breakage tables SHALL retain a location prerequisite and prevent duplicate product rows. Re-selecting a product through search SHALL focus or identify its existing row without resetting state. Barcode increments on existing adjustment rows SHALL follow the Stock Opname counting contract, while barcode increments on existing breakage rows SHALL follow the breakage delta contract. Breakage selection SHALL reject products outside the active setting or not managed as stock.

#### Scenario: Location has not been selected
- **WHEN** a user selects or scans a product on an adjustment or breakage create form without a selected location
- **THEN** no product row is added
- **AND** select-location feedback is shown

#### Scenario: Product is already selected through search
- **GIVEN** a product already exists in an adjustment or breakage table
- **WHEN** the user selects it again through search
- **THEN** no duplicate row is added and all existing quantities and serials are preserved

#### Scenario: Existing breakage product barcode is scanned
- **GIVEN** a non-serialized product already exists in the breakage table
- **WHEN** its product or supported conversion barcode is scanned
- **THEN** the existing row's single breakage quantity increases according to the breakage delta contract without adding another row

#### Scenario: Product is outside breakage scope
- **WHEN** search or scan resolves a product outside the active setting or a product that is not stock-managed
- **THEN** the breakage table rejects the selection without changing its rows

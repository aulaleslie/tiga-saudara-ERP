## MODIFIED Requirements

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

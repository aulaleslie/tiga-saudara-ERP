## MODIFIED Requirements

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

### Requirement: Existing selection guards remain effective
Adjustment and breakage tables SHALL retain a location prerequisite and prevent duplicate product rows. Re-selecting an adjustment product through search SHALL focus its existing row without resetting counts. Breakage SHALL retain its existing duplicate-product feedback. Barcode increments on existing adjustment rows SHALL follow the stock-opname counting contract rather than be rejected as duplicate-product selection.

#### Scenario: Location has not been selected
- **WHEN** a user selects a product on an adjustment or breakage create form without a selected location
- **THEN** no product row is added
- **AND** select-location feedback is shown

#### Scenario: Product is already selected
- **GIVEN** a product already exists in the adjustment or breakage table
- **WHEN** the user selects it again through search
- **THEN** no duplicate row is added
- **AND** adjustment focuses the existing row with counts preserved while breakage shows its existing duplicate-product feedback

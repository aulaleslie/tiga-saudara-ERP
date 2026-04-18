## ADDED Requirements

### Requirement: Product selection creates a new standard Sales cart row
The system SHALL create a new standard Sales cart row every time a product is selected from product search on the sales create or edit page.

#### Scenario: Selecting the same product twice creates duplicate cart rows
- **WHEN** a user selects a product from product search on the standard Sales create or edit page
- **AND** selects the same product again
- **THEN** the sales cart SHALL contain two distinct rows for that product
- **AND** the second selection SHALL NOT append quantity to the first row

#### Scenario: Selecting the same bundle product twice creates duplicate cart rows
- **WHEN** a user selects a bundle parent product from product search on the standard Sales create or edit page
- **AND** chooses the same bundle for that product more than once
- **THEN** the sales cart SHALL contain one distinct parent row for each selection
- **AND** the later selection SHALL NOT append quantity to an existing matching bundle row

### Requirement: Standard Sales create preserves cart rows as document lines
The system SHALL persist each standard Sales cart row as a separate `sale_details` row when creating a sale.

#### Scenario: Create sale with duplicate matching product rows
- **WHEN** a standard Sales cart contains two rows with the same product, tax, and bundle state
- **AND** the user creates the sale
- **THEN** the persisted sale SHALL contain two `sale_details` rows for those cart rows
- **AND** the rows SHALL NOT be merged into one row by summing quantity

#### Scenario: Create sale preserves bundle parent ownership
- **WHEN** a standard Sales cart contains duplicate bundle parent rows with the same product, tax, and bundle
- **AND** the user creates the sale
- **THEN** each persisted parent `sale_details` row SHALL have its own associated `sale_bundle_items`
- **AND** bundle child rows SHALL NOT be combined under a single parent sale detail

#### Scenario: Create sale totals remain equivalent to the cart
- **WHEN** a standard Sales cart contains duplicate rows with prices, taxes, discounts, shipping, or bundle totals
- **AND** the user creates the sale
- **THEN** the saved sale header totals SHALL equal the totals calculated from the visible cart rows

### Requirement: Standard Sales update preserves cart rows as document lines
The system SHALL persist each standard Sales cart row as a separate `sale_details` row when updating a sale.

#### Scenario: Update sale with duplicate matching product rows
- **WHEN** a user edits a standard sale so the cart contains two rows with the same product, tax, and bundle state
- **AND** the user saves the update
- **THEN** the updated sale SHALL contain two `sale_details` rows for those cart rows
- **AND** the rows SHALL NOT be merged into one row by summing quantity

#### Scenario: Update sale replaces old rows with preserved current rows
- **WHEN** a user edits a standard sale with existing sale details
- **AND** changes the cart to contain duplicate matching product rows
- **THEN** the update SHALL replace the old detail set with the current cart rows
- **AND** each current cart row SHALL be persisted as its own sale detail

### Requirement: Standard Sales edit restores preserved document rows
The system SHALL restore saved standard Sales document rows as separate cart rows when opening a sale for edit.

#### Scenario: Edit page loads duplicate sale details separately
- **WHEN** a standard sale has multiple `sale_details` rows with the same product, tax, and bundle state
- **AND** a user opens the sale edit page
- **THEN** the sales cart SHALL display each saved sale detail as a separate cart row
- **AND** the edit load SHALL NOT collapse matching saved rows

### Requirement: Dispatch aggregates preserved document rows by fulfillment key
The system SHALL keep dispatch demand aggregation based on the sale parent and product/tax/bundle fulfillment keys, regardless of how many document rows exist.

#### Scenario: Dispatch view aggregates duplicate sale details
- **WHEN** a standard sale has multiple `sale_details` rows with the same product, tax, and bundle state
- **AND** a user opens the dispatch page
- **THEN** the dispatch product table SHALL show aggregate dispatchable quantity for that product/tax/bundle
- **AND** the aggregate quantity SHALL equal the sum of the matching saved sale detail quantities

#### Scenario: Dispatch validation uses aggregate remaining quantity
- **WHEN** a standard sale has duplicate saved sale details for the same product, tax, and bundle state
- **AND** a user submits a dispatch quantity for that product/tax/bundle
- **THEN** validation SHALL compare the submitted quantity against the aggregate remaining quantity for the sale parent
- **AND** validation SHALL NOT require a specific `sale_details` row to be selected

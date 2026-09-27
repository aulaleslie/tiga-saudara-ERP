## ADDED Requirements

### Requirement: Authorized users can open cross-business product price management
The system SHALL register a dedicated product permission for cross-business price management. Only a user granted that permission SHALL see the corresponding action on the product list or access the management page and its save operation.

#### Scenario: Authorized user sees and opens the action
- **WHEN** a user with the cross-business product price-management permission views the product list
- **THEN** the system SHALL show an action for the selected product
- **AND** the action SHALL open that product's cross-business price-management page

#### Scenario: Unauthorized user cannot access price management
- **WHEN** a user without the cross-business product price-management permission requests the action, page, or save operation
- **THEN** the system SHALL deny access
- **AND** the system SHALL NOT disclose cross-business price data

### Requirement: The page displays every business price in a safe view state
The cross-business price-management page SHALL list every business setting for the selected product. It SHALL initially display all price values read-only and SHALL provide a control to return to the product list.

#### Scenario: Existing business price row is displayed
- **WHEN** a price row exists for the selected product and a business setting
- **THEN** the page SHALL display that setting's sales price, tier 1 price, tier 2 price, last purchase price, and average purchase price
- **AND** all values SHALL be read-only initially

#### Scenario: Missing business price row defaults to zero
- **WHEN** no price row exists for the selected product and a listed business setting
- **THEN** the page SHALL display zero for sales price, tier 1 price, tier 2 price, last purchase price, and average purchase price

#### Scenario: User returns to the product list
- **WHEN** the user activates the Back control from the cross-business price-management page
- **THEN** the system SHALL navigate to the product list

### Requirement: Page-level editing limits changes to commercial prices
The page SHALL enter edit mode only after the user activates `Ubah`. In edit mode, sales price, tier 1 price, tier 2 price, and last purchase price SHALL be editable. Average purchase price SHALL remain read-only in all states.

#### Scenario: User enters edit mode
- **WHEN** the user activates `Ubah`
- **THEN** the system SHALL make sales price, tier 1 price, tier 2 price, and last purchase price editable for every listed business
- **AND** the system SHALL continue displaying average purchase price as read-only

#### Scenario: User cancels edit mode
- **WHEN** the user activates `Batal` before saving
- **THEN** the system SHALL discard unsaved price inputs
- **AND** the system SHALL restore the page's read-only state using the loaded values

### Requirement: Bulk save is atomic and preserves purchase average and tax metadata
The system SHALL validate and save all editable business prices in one transaction. It SHALL update existing rows or create missing rows, without modifying any existing average purchase price or tax assignment.

#### Scenario: Valid bulk save updates every existing row
- **WHEN** the user submits valid non-negative values for all editable prices
- **AND** all listed price rows remain current
- **THEN** the system SHALL update sales price, tier 1 price, tier 2 price, and last purchase price for every existing listed row in one transaction
- **AND** the system SHALL preserve each row's average purchase price, purchase tax ID, and sales tax ID
- **AND** the page SHALL return to read-only state with the saved values

#### Scenario: Valid bulk save creates a missing row
- **WHEN** the user submits valid editable prices for a business that had no product price row
- **THEN** the system SHALL create one row for that product and business
- **AND** the new row SHALL store the submitted sales, tier 1, tier 2, and last purchase prices
- **AND** the new row SHALL set average purchase price to zero and tax IDs to null

#### Scenario: Invalid input leaves every row unchanged
- **WHEN** any submitted editable price is absent, non-numeric, or less than zero
- **THEN** the system SHALL reject the save with validation feedback
- **AND** the system SHALL NOT create or modify any listed price row

### Requirement: Bulk save rejects stale data and duplicate interaction safely
The system SHALL prevent a loaded page from overwriting prices saved by another request and SHALL prevent duplicate user submission while a save is pending.

#### Scenario: A row changed after page load
- **WHEN** any existing product price row has changed since the user loaded the management page
- **THEN** the system SHALL reject the entire bulk save
- **AND** the system SHALL NOT apply changes to any other business row
- **AND** the system SHALL instruct the user to reload the page

#### Scenario: User double-clicks Save
- **WHEN** the user activates Save more than once while the first save is pending
- **THEN** the interface SHALL disable subsequent Save interactions until the request finishes
- **AND** the persisted result SHALL remain equivalent to a single valid save

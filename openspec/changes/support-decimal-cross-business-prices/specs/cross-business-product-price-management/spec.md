## MODIFIED Requirements

### Requirement: The page displays every business price in a safe view state
The cross-business price-management page SHALL list every business setting for the selected product. It SHALL initially display all price values in a non-editable state that cannot be mutated by native input behavior, money-mask handlers, keyboard input, deletion, or paste, and SHALL provide a control to return to the product list.

#### Scenario: Existing business price row is displayed
- **WHEN** a price row exists for the selected product and a business setting
- **THEN** the page SHALL display that setting's sales price, tier 1 price, tier 2 price, last purchase price, and average purchase price
- **AND** all values SHALL be non-editable initially

#### Scenario: View-state interaction cannot mutate a commercial price
- **WHEN** the page is still in its initial view state
- **AND** the user types, deletes, pastes, or otherwise interacts with a commercial price control
- **THEN** its displayed and submitted value SHALL remain unchanged
- **AND** edit-only controls SHALL remain unavailable

#### Scenario: Missing business price row defaults to zero
- **WHEN** no price row exists for the selected product and a listed business setting
- **THEN** the page SHALL display zero for sales price, tier 1 price, tier 2 price, last purchase price, and average purchase price

#### Scenario: User returns to the product list
- **WHEN** the user activates the Back control from the cross-business price-management page
- **THEN** the system SHALL navigate to the product list

### Requirement: Page-level editing limits changes to commercial prices
The page SHALL enter edit mode only after the user activates `Ubah`. In edit mode, sales price, tier 1 price, tier 2 price, and last purchase price SHALL accept non-negative values with up to two decimal places. Average purchase price SHALL remain non-editable in all states.

#### Scenario: User enters edit mode
- **WHEN** the user activates `Ubah`
- **THEN** the system SHALL make sales price, tier 1 price, tier 2 price, and last purchase price editable for every listed business
- **AND** those fields SHALL accept values with up to two decimal places
- **AND** the system SHALL continue displaying average purchase price as non-editable

#### Scenario: User cancels edit mode
- **WHEN** the user activates `Batal` before saving
- **THEN** the system SHALL discard unsaved whole-number or decimal price inputs
- **AND** the system SHALL restore the non-editable view state using the exact loaded values

### Requirement: Currency formatting preserves product price magnitude
The cross-business price-management page SHALL represent decimal-backed price values using Indonesian currency separators with up to two decimal places. Formatting, editing, copying a column to all businesses, cancellation, validation restoration, and unchanged submission MUST preserve both numeric magnitude and supported fractional precision.

#### Scenario: Two-decimal database value is displayed at the correct magnitude
- **WHEN** a business product price field contains the value `2500000.75`
- **THEN** the page SHALL display it as `2.500.000,75`
- **AND** the page SHALL NOT round it to a whole Rupiah or multiply its magnitude

#### Scenario: Every displayed price field uses consistent decimal formatting
- **WHEN** the page loads sales price, tier 1 price, tier 2 price, last purchase price, and average purchase price values
- **THEN** each value SHALL use consistent Indonesian thousands and decimal separators
- **AND** each displayed value SHALL retain up to two stored decimal places

#### Scenario: Locale-formatted input is submitted canonically
- **WHEN** a user enters `1.234,56` in an editable price field and saves
- **THEN** the request boundary SHALL interpret the value as `1234.56`
- **AND** the persisted value SHALL be numerically equal to `1234.56`

#### Scenario: Apply to all preserves a decimal value
- **WHEN** a user applies a changed value of `1.234,56` to the same column for every business
- **THEN** each target field SHALL contain a value numerically equal to `1234.56`
- **AND** saving SHALL preserve that decimal value for every target row

#### Scenario: Cancel restores the loaded decimal value
- **WHEN** a user edits a commercial price containing a fractional component and activates `Batal`
- **THEN** the page SHALL restore the exact loaded value at supported two-decimal precision
- **AND** reapplying locale formatting SHALL NOT change its magnitude or fraction

#### Scenario: Unchanged decimal submission preserves the price
- **WHEN** a user enters edit mode and submits a loaded `2500000.75` price without changing it
- **THEN** the system SHALL persist a price numerically equal to `2500000.75`
- **AND** the saved value SHALL NOT be rounded or multiplied

#### Scenario: Validation restoration preserves decimal input
- **WHEN** a save fails validation after a user entered a valid two-decimal price in another field
- **THEN** the page SHALL restore that valid price with the same numeric magnitude and fractional value
- **AND** dirty-state detection SHALL compare it using decimal precision rather than whole-number rounding

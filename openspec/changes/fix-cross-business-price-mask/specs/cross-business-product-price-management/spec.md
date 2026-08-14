## ADDED Requirements

### Requirement: Currency formatting preserves product price magnitude
The cross-business price-management page SHALL normalize decimal-backed price values to its zero-decimal Rupiah input representation before applying locale formatting. Formatting, editing, cancellation, validation restoration, and unchanged submission MUST NOT multiply or otherwise change a price's numeric magnitude.

#### Scenario: Two-decimal database value is displayed at the correct magnitude
- **WHEN** a business product price field contains the decimal-backed value `2500000.00`
- **THEN** the page SHALL display the value as `2.500.000` under Indonesian zero-decimal formatting
- **AND** the page SHALL NOT display the value as `250.000.000`

#### Scenario: Every displayed price field uses consistent normalization
- **WHEN** the page loads sales price, tier 1 price, tier 2 price, last purchase price, and average purchase price values
- **THEN** each value SHALL be normalized before the zero-decimal currency mask is applied
- **AND** each displayed value SHALL retain the magnitude of its stored numeric value

#### Scenario: Cancel restores the loaded magnitude
- **WHEN** a user edits a commercial price and then activates `Batal`
- **THEN** the page SHALL restore the originally loaded zero-decimal value
- **AND** reapplying the currency mask SHALL NOT change that value's magnitude

#### Scenario: Unchanged submission preserves the price
- **WHEN** a user enters edit mode and submits a loaded `2500000.00` price without changing it
- **THEN** the system SHALL persist a price numerically equal to `2500000.00`
- **AND** the saved value SHALL NOT become `250000000.00`

#### Scenario: Fractional Rupiah value follows zero-decimal precision
- **WHEN** a stored or validation-restored price contains a fractional component
- **THEN** the page SHALL round it to the nearest whole Rupiah before applying the zero-decimal mask
- **AND** the resulting value SHALL remain stable through edit and cancel interactions

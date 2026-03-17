## ADDED Requirements

### Requirement: Real-time amount formatting with thousand separators
The payment amount input field SHALL display numeric values with Indonesian thousand separators (1000 displays as 1.000) while maintaining the raw numeric value for calculations and submission.

#### Scenario: User enters amount without formatting
- **WHEN** user types "150000" into the amount input field
- **THEN** the field displays "150.000" with proper thousand separators
- **AND** the raw numeric value 150000 is stored for submission

#### Scenario: User edits formatted amount
- **WHEN** user has "100.000" displayed and types additional digits "50"
- **THEN** the field displays "10050" during typing
- **AND** after keystroke completes, it displays "10.050"
- **AND** raw value is 10050

#### Scenario: User pastes unformatted number
- **WHEN** user pastes "25000" into the amount field
- **THEN** the display immediately reformats to "25.000"
- **AND** raw numeric value becomes 25000

#### Scenario: User clears field
- **WHEN** user deletes all characters in the amount field
- **THEN** the field displays empty (no default values)
- **AND** raw numeric value is cleared

#### Scenario: Non-numeric characters rejected
- **WHEN** user attempts to enter non-numeric characters (letters, symbols)
- **THEN** those characters are stripped from input
- **AND** only numeric digits are retained and displayed with formatting

### Requirement: Raw numeric value preservation for submission
The amount input field SHALL store the raw numeric value (without separators) in a separate data attribute so that form submission sends the correct numeric value to the backend.

#### Scenario: Formatted display vs submission value
- **WHEN** amount displays as "150.000"
- **THEN** the raw value 150000 is stored in `dataset.rawValue`
- **AND** form submission uses the raw value (not the formatted string)

#### Scenario: Payment calculation uses raw value
- **WHEN** payment validator checks if amount is within remainder
- **THEN** it uses the raw numeric value (150000) not the formatted display (150.000)
- **AND** calculation accuracy is maintained (no floating-point errors)

### Requirement: Formatting respects Indonesian locale
The amount formatter SHALL use the Indonesian locale (id-ID) for thousand separators and numeric formatting to match regional conventions.

#### Scenario: Indonesian number format applied
- **WHEN** amount 1000000 is entered
- **THEN** display shows "1.000.000" (Indonesian format with periods as separators)
- **AND** not "1,000,000" (US format) or other locale variants

### Requirement: Real-time validation trigger on format change
When the amount input is formatted, the system SHALL trigger validation immediately so that the submit button state updates based on the new amount.

#### Scenario: Validation enables submit button
- **WHEN** user enters an amount equal to the remainder (e.g., 150.000 when remainder is 150000)
- **THEN** formatting completes
- **AND** validation runs automatically
- **AND** the [Lanjut Pembayaran] button becomes enabled (if other conditions met)

#### Scenario: Validation disables submit button
- **WHEN** user enters an amount exceeding the remainder (e.g., 200.000 when remainder is 150000) for non-cash payment method
- **THEN** formatting completes
- **AND** validation runs automatically
- **AND** the [Lanjut Pembayaran] button becomes disabled
- **AND** error message displays (via existing validateBeforeSubmit logic)

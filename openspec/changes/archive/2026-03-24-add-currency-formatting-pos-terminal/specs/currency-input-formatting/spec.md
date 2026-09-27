## ADDED Requirements

### Requirement: Currency input field displays formatted value on blur
The currency input field SHALL display the entered value in formatted currency notation (e.g., "Rp 1.000,00") when the user leaves the field (blur event), improving readability and providing visual feedback.

#### Scenario: User enters numeric value and moves to next field
- **WHEN** user enters "1000" in the currency field and presses Tab or clicks elsewhere
- **THEN** the field displays "Rp 1.000,00" with proper thousands separator and two decimal places

#### Scenario: User enters decimal value
- **WHEN** user enters "5000.50" in the currency field and the field loses focus
- **THEN** the field displays "Rp 5.000,50"

### Requirement: Currency input field reverts to plain number on focus
The currency input field SHALL display as a plain numeric value when the user focuses on it, allowing unformatted editing.

#### Scenario: User clicks on a formatted currency field
- **WHEN** user clicks on a field showing "Rp 1.000,00"
- **THEN** the field displays the plain number "1000.00" or "1000" for easy editing

#### Scenario: User uses Tab to navigate into field
- **WHEN** user presses Tab to focus on a formatted currency field
- **THEN** the field displays the plain numeric value

### Requirement: Form submission sends raw numeric values
The form SHALL extract unformatted numeric values from currency fields before submission, ensuring the database receives valid numeric data.

#### Scenario: User submits form with formatted currency field
- **WHEN** user clicks Submit with a field displaying "Rp 1.000,00"
- **THEN** the form submits the value as "1000.00" or "1000" (raw numeric)

#### Scenario: Negative values are supported
- **WHEN** a field contains a negative amount and the form is submitted
- **THEN** the raw negative numeric value is sent to the server

### Requirement: Currency formatting uses Indonesian locale settings
The currency display SHALL use the configured Indonesian Rupiah settings (symbol, thousand separator, decimal separator).

#### Scenario: Format uses Rp symbol with proper separators
- **WHEN** a currency field is formatted
- **THEN** it displays as "Rp X.XXX,XX" with period (.) as thousand separator and comma (,) as decimal separator

#### Scenario: Settings can be customized per installation
- **WHEN** currency settings are updated via application configuration
- **THEN** newly formatted fields use the new symbol and separators

### Requirement: Empty or zero values are handled gracefully
The currency field SHALL handle empty values and zero amounts without displaying errors.

#### Scenario: User leaves field empty
- **WHEN** user focuses away from an empty currency field
- **THEN** the field remains empty or displays zero (no error)

#### Scenario: User enters zero
- **WHEN** user enters "0" in a currency field and loses focus
- **THEN** the field displays "Rp 0,00"

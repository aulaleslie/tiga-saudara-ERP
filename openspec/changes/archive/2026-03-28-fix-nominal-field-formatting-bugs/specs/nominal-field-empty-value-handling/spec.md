## ADDED Requirements

### Requirement: Empty nominal field initialization
The system SHALL initialize empty nominal fields with maskMoney formatting active, displaying "0,00" as the initial formatted value when the field has no value or an empty string value.

#### Scenario: Create page with empty price field
- **WHEN** product create page loads with no initial purchase_price value
- **THEN** the visible input displays "0,00" (formatted currency)
- **AND** maskMoney is active and ready to accept user input

#### Scenario: Field with explicit zero value
- **WHEN** a price field is initialized with the value 0 or "0"
- **THEN** the visible input displays "0,00" (formatted currency)
- **AND** maskMoney formatting is active

#### Scenario: User enters value in empty field and blurs
- **WHEN** user focuses an empty field (displaying "0,00")
- **AND** user types "5000"
- **AND** user clicks outside the field (blur event)
- **THEN** the visible input displays "Rp 5.000,00" (formatted)
- **AND** the hidden input stores the raw value "5000"

### Requirement: Empty field placeholder consistency
The system SHALL use the locale-specific placeholder format to indicate empty price fields to users.

#### Scenario: Placeholder text on empty field
- **WHEN** a price field is empty and has focus
- **THEN** the placeholder text shows "0,00" (indicating currency format)
- **AND** user can type numbers directly without seeing the placeholder

### Requirement: No blank-field display
The system SHALL NOT display empty nominal fields as blank/whitespace on page load.

#### Scenario: Avoid blank field UX
- **WHEN** product create or edit page renders
- **AND** a price field has no initial value
- **THEN** the field shows "0,00" (not empty/blank)
- **AND** the field appears formatted and active (not disabled or placeholder-only)

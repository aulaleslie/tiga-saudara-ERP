## MODIFIED Requirements

### Requirement: EDC reference is validated when required
The system SHALL require an EDC reference for non-cash payment methods that require a reference, validating only that the field is not empty.

#### Scenario: EDC reference required and provided
- **WHEN** a user selects a non-cash payment method that requires a reference (e.g., Bank Transfer) and enters any non-empty value in the EDC reference field
- **THEN** the system accepts the reference without format validation (spaces, special characters, any length are allowed)

#### Scenario: EDC reference required but empty
- **WHEN** a user selects a non-cash payment method that requires a reference and leaves the reference field empty
- **THEN** the system displays error "Nomor referensi EDC wajib diisi" and prevents payment submission

#### Scenario: EDC reference not required
- **WHEN** a user selects a cash payment method or a non-cash method that does not require a reference
- **THEN** the system does not display the EDC reference field and does not require a value

#### Scenario: Realtime validation for required reference field
- **WHEN** a user focuses on or types in the EDC reference field for a payment method that requires it
- **THEN** the field shows invalid state (CSS class `is-invalid`) if empty, and valid state (class removed) when a value is entered

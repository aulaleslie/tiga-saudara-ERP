## MODIFIED Requirements

### Requirement: Error Logging on Save Failure
When a database exception or any other error prevents a customer from being created, the system MUST log the error. When the error is an integrity constraint violation (duplicate entry), the system SHALL redirect back with a user-friendly validation error message identifying the conflicting field, instead of showing the generic "Hubungi Administrator" toast.

#### Scenario: Duplicate email caught at database level
- **WHEN** customer creation fails due to a duplicate `customer_email` constraint violation
- **THEN** the system SHALL log the error at error level
- **AND** the system SHALL redirect back with a validation error "Email sudah digunakan" on the `customer_email` field
- **AND** the system SHALL NOT show the generic "Hubungi Administrator" toast

#### Scenario: Duplicate phone caught at database level
- **WHEN** customer creation fails due to a duplicate `customer_phone` constraint violation
- **THEN** the system SHALL log the error at error level
- **AND** the system SHALL redirect back with a validation error "Nomor telepon sudah digunakan" on the `customer_phone` field

#### Scenario: Unknown database error
- **WHEN** customer creation fails due to a non-constraint database error
- **THEN** the system SHALL log the error at error level with full trace
- **AND** the system SHALL show the generic "Hubungi Administrator" toast

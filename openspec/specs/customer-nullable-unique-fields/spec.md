# Customer Nullable Unique Fields

## Purpose
Ensure optional customer fields with unique constraints are persisted as `NULL` when left blank.

## Requirements
### Requirement: Empty optional unique fields stored as NULL

The system SHALL store NULL instead of empty string for optional fields that have unique database constraints (`customer_email`, `identity_number`, `npwp`) when the user leaves them blank.

#### Scenario: Two customers created without email
- **WHEN** a user creates a customer with no email via the admin form
- **AND** a second customer is created with no email via the admin form
- **THEN** both records SHALL be saved successfully
- **AND** both records SHALL have `customer_email` set to NULL

#### Scenario: Two customers created without NPWP
- **WHEN** a user creates a customer with no NPWP
- **AND** a second customer is created with no NPWP
- **THEN** both records SHALL be saved successfully
- **AND** both records SHALL have `npwp` set to NULL

#### Scenario: Two customers created without identity number
- **WHEN** a user creates a customer with no identity number
- **AND** a second customer is created with no identity number
- **THEN** both records SHALL be saved successfully
- **AND** both records SHALL have `identity_number` set to NULL

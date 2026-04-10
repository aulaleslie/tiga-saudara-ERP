## ADDED Requirements

### Requirement: Cross-Tenant Detail Bypass
The system SHALL bypass strict tenant isolation validations on the standard detail routes specifically for users leveraging the `globalSalesSearch.access` authentication gate.

#### Scenario: Super admin views isolated sale target
- **WHEN** user with `globalSalesSearch.access` attempts to open a details page for a `Sale` located in a different tenant setting
- **THEN** system bypasses the 404 block and successfully renders `/sales/{id}`

#### Scenario: Super admin views isolated POS transaction
- **WHEN** user with `globalSalesSearch.access` attempts to open a details page for a `PosTransaction` located in a different tenant setting
- **THEN** system bypasses the 404 block and successfully renders `/pos/transactions/{id}`

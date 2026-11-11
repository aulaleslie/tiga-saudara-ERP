## ADDED Requirements

### Requirement: Stock insights is limited to assigned businesses
`Stok Global`, the per-business and per-location columns, attention statuses, and sales and financial measures SHALL include only businesses assigned to the authenticated user, or every business for Super Admin. The report SHALL NOT add a business selector.

#### Scenario: User assigned to a subset
- **WHEN** a user assigned to A and B (not C) opens Pantauan Stok
- **THEN** only A and B SHALL appear as business columns
- **AND** `Stok Global` and the sales measures SHALL exclude C

#### Scenario: Super Admin
- **WHEN** a Super Admin opens Pantauan Stok
- **THEN** every business SHALL be included

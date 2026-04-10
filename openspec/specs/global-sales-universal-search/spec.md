## ADDED Requirements

### Requirement: Unified Polymorphic Search
The system SHALL execute searches across both `Sale` and `PosTransaction` databases simultaneously, returning a single paginated list of unified results sorted chronologically.

#### Scenario: User searches for a term matching both
- **WHEN** user searches for a customer name who has both draft POS transactions and completed Sales
- **THEN** system returns rows for both entities in the same table

### Requirement: Search by Barcode and POS Identifiers
Users SHALL be able to search using varied criteria including product barcodes, POS receipt numbers, and POS transaction codes across the unified data layer.

#### Scenario: POS transaction code match
- **WHEN** user enters a POS code
- **THEN** system returns the `PosTransaction` matching the code, and equivalently any `Sale` associated.

### Requirement: Search by User Attributes
Users SHALL be able to search for records corresponding to the staff member operating the transaction.

#### Scenario: Creator user match
- **WHEN** user enters the system name of a seller or cashier
- **THEN** system returns sales matching the seller (created_by) or POS cashier user

### Requirement: Polymorphic Routing Details
The UI SHALL route users to the appropriate detail page tailored to the underlying entity type.

#### Scenario: Sale Link Click
- **WHEN** user clicks view on a `Sale` row
- **THEN** system opens `/sales/{id}`

#### Scenario: POS Transaction Link Click
- **WHEN** user clicks view on a `PosTransaction` row
- **THEN** system opens `/pos/transactions/{id}`

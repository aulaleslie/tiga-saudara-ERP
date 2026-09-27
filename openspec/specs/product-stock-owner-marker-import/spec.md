## MODIFIED Requirements

### Requirement: Product matching and creation
The system SHALL match only existing products by the shared canonical catalog identity and SHALL never create products from an Accurate XLSX stock snapshot row.

#### Scenario: Product already exists
- **WHEN** a row's canonical clean product identity uniquely matches an existing product according to the shared import matching rules
- **THEN** the system SHALL use that existing product
- **AND** the system SHALL NOT create a duplicate product for a different owner marker or formatting variant

#### Scenario: Product does not exist or is conflicted
- **WHEN** a row's canonical clean product identity does not uniquely match an existing product
- **THEN** the system SHALL skip or error the row with an actionable unmatched or ambiguous identity reason
- **AND** the system SHALL NOT create a product, unit, price row, stock row, or transaction

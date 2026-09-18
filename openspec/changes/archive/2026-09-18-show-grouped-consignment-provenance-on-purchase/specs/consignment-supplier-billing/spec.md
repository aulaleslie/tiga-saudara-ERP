## ADDED Requirements

### Requirement: Generated Purchases display grouped consignment source evidence
For a Purchase generated from consignment billing, the detail page SHALL show the consignment receival reference and receiving number for contributing lineage within each existing Purchase product row. It SHALL group lineage by source receival and receiving identity, sum the billed base quantity within each group, and show each billed serial number associated with that group. This presentation SHALL leave Purchase details and immutable lineage records unchanged. It SHALL NOT present internal database IDs as document numbers or a bare `SN` marker as serial evidence.

#### Scenario: Multiple serialized allocations share one source
- **WHEN** several serialized lineage rows on one Purchase detail belong to the same consignment receival and receiving
- **THEN** the detail page SHALL show one source group with their summed billed quantity
- **AND** it SHALL show each associated serial number once within that group

#### Scenario: One Purchase detail spans different sources
- **WHEN** lineage rows on one Purchase detail come from different receivals or receivings
- **THEN** the detail page SHALL show separate groups with their own source numbers, quantities, and serial numbers
- **AND** their displayed quantities SHALL sum to that Purchase detail's billed lineage quantity

#### Scenario: Non-serialized allocation
- **WHEN** a source group has non-serialized lineage quantity
- **THEN** the detail page SHALL include that quantity in the group total
- **AND** it SHALL not imply that a serial number exists for that allocation

#### Scenario: Historical source number is unavailable
- **WHEN** a lineage source document or serial number cannot be resolved
- **THEN** the detail page SHALL display an unavailable indicator for that value while retaining the grouped quantity
- **AND** it SHALL not substitute a database ID as a document or serial number

#### Scenario: Ordinary Purchase is viewed
- **WHEN** a Purchase was not generated from consignment billing
- **THEN** its product rows SHALL retain their existing display without consignment source groups

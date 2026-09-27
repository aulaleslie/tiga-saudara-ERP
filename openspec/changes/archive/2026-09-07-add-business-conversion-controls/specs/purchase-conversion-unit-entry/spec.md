## MODIFIED Requirements

### Requirement: Purchase lines accept product base and conversion units
The system SHALL let an authorized user create or edit a mutable Purchase line using the product's base unit or an active eligible conversion belonging to that product and enabled for purchases in the acting business. The system SHALL treat the base unit as factor `1` and SHALL reject a submitted conversion that is unrelated, invalid, inactive for new activity, or incompatible with the product's current base unit.

#### Scenario: User adds a conversion-unit line
- **WHEN** a user selects a product conversion where one BOX equals twelve PCS and enters two BOX
- **THEN** the Purchase cart SHALL retain two BOX as the entered representation
- **AND** it SHALL resolve the canonical quantity as twenty-four PCS

#### Scenario: User selects the base unit
- **WHEN** a user selects the product's base unit on a Purchase line
- **THEN** the system SHALL use an implicit conversion factor of `1`
- **AND** entered quantity SHALL equal canonical quantity

#### Scenario: Submitted conversion does not belong to product
- **WHEN** a client submits another product's conversion identity for a Purchase line
- **THEN** the server SHALL reject the line
- **AND** it MUST NOT trust a client-supplied conversion factor or canonical quantity

#### Scenario: Same product uses multiple units
- **WHEN** a Purchase contains the same product as two BOX and three PCS
- **THEN** the cart SHALL retain separate BOX and PCS lines
- **AND** adding the same product in the same selected unit SHALL increment only the matching line

#### Scenario: Purchase-disabled conversion is hidden and rejected
- **WHEN** a product has a purchase-disabled BOX conversion in the acting business
- **THEN** selecting that product SHALL omit BOX from new unit options and retain the base unit
- **AND** direct submission of BOX for a new or switched line SHALL be rejected by the server

#### Scenario: Sales flag does not restrict purchase eligibility
- **WHEN** BOX is sales-disabled but purchase-enabled and otherwise eligible
- **THEN** BOX SHALL remain available for new Purchase selection

#### Scenario: Duplicate excludes a now-disabled conversion
- **WHEN** a Purchase is duplicated and its original BOX conversion is now purchase-disabled
- **THEN** duplication SHALL use the existing canonical base-unit fallback and clear conversion intent
- **AND** it SHALL NOT introduce BOX through historical option fallback

#### Scenario: Persisted snapshots survive disablement
- **WHEN** a saved Purchase line references BOX and BOX is subsequently purchase-disabled
- **THEN** historical rendering, permitted unchanged-line edits, and receiving SHALL retain its original snapshots and existing restrictions
- **AND** snapshot fallback SHALL NOT authorize BOX for a new or switched line


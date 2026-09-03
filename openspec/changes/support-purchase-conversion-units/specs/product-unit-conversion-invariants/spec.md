## ADDED Requirements

### Requirement: Product base unit is the smallest counting unit
The system SHALL treat a stock-managed product's base unit as its smallest counting and inventory unit. Every newly created or edited product conversion SHALL mean `1 conversion unit = conversion factor × base unit` and SHALL have a factor strictly greater than `1`.

#### Scenario: User creates a valid larger-unit conversion
- **WHEN** a user configures one BOX as twelve base-unit PCS
- **THEN** the system SHALL accept conversion factor `12`

#### Scenario: User submits factor one
- **WHEN** a user creates or edits a conversion with factor `1`
- **THEN** the system SHALL reject it because the base unit already represents factor `1`

#### Scenario: User submits factor below one
- **WHEN** a user creates or edits a conversion with a positive factor below `1`
- **THEN** the system SHALL reject it because conversion units must be larger than the base unit

### Requirement: Serialized product conversions use whole factors
The system SHALL require every newly created or edited conversion factor for a serial-tracked product to be a whole number greater than `1`, so one conversion unit always resolves to a whole number of serial-bearing base units.

#### Scenario: Serialized product uses whole conversion factor
- **WHEN** a serial-tracked product configures one BOX as twelve base units
- **THEN** the system SHALL accept the factor

#### Scenario: Serialized product uses decimal conversion factor
- **WHEN** a serial-tracked product submits conversion factor `2.5`
- **THEN** the system SHALL reject the conversion with an actionable validation error

### Requirement: Conversion invariant is enforced across write paths
The system SHALL enforce conversion-factor and product-association invariants on normal product create/edit, shared product quick-add, and every other supported request or import path that writes product conversions. Client-side restrictions MUST NOT replace server-side validation.

#### Scenario: Crafted request bypasses browser constraints
- **WHEN** a client directly submits an invalid factor through a product conversion endpoint
- **THEN** server-side validation SHALL reject the write

#### Scenario: Quick-add creates a conversion
- **WHEN** a conversion is submitted through product quick-add
- **THEN** it SHALL be subject to the same factor and serialization rules as the full product form

### Requirement: Invalid legacy conversions are not silently rewritten
The system MUST NOT automatically rewrite an existing conversion whose factor violates the new invariant. Such a conversion SHALL be excluded from new Purchase selection and SHALL remain available only as needed to interpret existing behavior or historical snapshots until a future audited product-UOM rebasing workflow corrects it.

#### Scenario: Legacy factor is less than or equal to one
- **WHEN** an existing product has a conversion factor less than or equal to `1`
- **THEN** the conversion SHALL NOT be selectable for a new Purchase line
- **AND** the system MUST NOT silently change the factor or historical quantities

#### Scenario: Historical Purchase references legacy conversion
- **WHEN** an existing Purchase line references or snapshots a now-invalid legacy conversion
- **THEN** the system SHALL continue rendering that historical line from persisted evidence
- **AND** it MUST NOT reinterpret the line using a replacement current factor


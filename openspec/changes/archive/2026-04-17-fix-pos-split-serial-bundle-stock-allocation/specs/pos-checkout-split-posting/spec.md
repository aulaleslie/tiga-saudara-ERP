## ADDED Requirements

### Requirement: Split planning SHALL preserve serial parent allocations
When split posting plans a stock-managed serial-tracked POS checkout line, the system SHALL provide a usable parent allocation to the grouped posting context for that line. The grouped parent allocation MUST include source location, source setting, allocated quantity, tax bucket usage, tax policy snapshot, and assigned serial information needed for posting.

#### Scenario: Serial parent allocation survives split planning
- **WHEN** split posting is enabled and checkout finalization plans a stock-managed serial-tracked parent line with valid assigned serials
- **THEN** each grouped line for that parent has a non-empty parent allocation under the grouped line index and/or grouped parent allocation key
- **AND** the inline posting adapter does not fail with missing stock allocation for that parent product

#### Scenario: Serial parent split across two source groups
- **WHEN** a stock-managed serial-tracked parent line has assigned serials from two different allowed source location or tax-bucket groups
- **THEN** split planning creates one grouped parent line per source group
- **AND** each grouped parent line receives only the serial allocation and quantity for that group

### Requirement: Split planning SHALL not duplicate bundle child allocations across groups
When split posting plans a bundled POS checkout line, bundle child allocations SHALL be scoped to the grouped parent line quantity. The system MUST NOT copy the original full-cart bundle child allocation into every split group.

#### Scenario: Bundle child allocation follows grouped parent quantity
- **WHEN** a bundled parent line is split into multiple source groups
- **THEN** each group receives child allocations whose total quantity equals that group's parent quantity multiplied by the child quantity-per-bundle
- **AND** the total child allocation across all groups equals the original required child quantity

#### Scenario: Single-source bundled serial checkout keeps child allocation once
- **WHEN** a bundled serial-tracked parent line is planned into one split group
- **THEN** the group receives exactly the bundle child allocation required for that grouped parent quantity
- **AND** no additional duplicate child allocation is attached to another group

## MODIFIED Requirements

### Requirement: Split planning SHALL preserve serial parent allocations
When split posting plans a stock-managed serial-tracked POS checkout line, the system SHALL provide a usable parent allocation to the grouped posting context for that line. The grouped parent allocation MUST include source location, source setting, allocated quantity, tax bucket usage, tax policy snapshot, and assigned serial information needed for posting. When the line is a bundle line, this preservation requirement SHALL also apply independently to each serial-required bundle component, so each component's assigned serials are carried into its grouped child allocation.

#### Scenario: Serial parent allocation survives split planning
- **WHEN** split posting is enabled and checkout finalization plans a stock-managed serial-tracked parent line with valid assigned serials
- **THEN** each grouped line for that parent has a non-empty parent allocation under the grouped line index and/or grouped parent allocation key
- **AND** the inline posting adapter does not fail with missing stock allocation for that parent product

#### Scenario: Serial parent split across two source groups
- **WHEN** a stock-managed serial-tracked parent line has assigned serials from two different allowed source location or tax-bucket groups
- **THEN** split planning creates one grouped parent line per source group
- **AND** each grouped parent line receives only the serial allocation and quantity for that group

#### Scenario: Serial-required bundle component allocation survives split planning
- **WHEN** split posting plans a bundle line containing a serial-required component with valid assigned serials
- **THEN** the grouped child allocation for that component MUST include the component's assigned serial information
- **AND** the posting adapter does not fail with missing stock allocation for that component.

#### Scenario: Serial-required bundle component split across two source groups
- **WHEN** a serial-required bundle component has assigned serials sourced from two different owner groups
- **THEN** split planning creates grouped child allocations per source group for that component
- **AND** each grouped child allocation receives only the serial subset and quantity belonging to that group.

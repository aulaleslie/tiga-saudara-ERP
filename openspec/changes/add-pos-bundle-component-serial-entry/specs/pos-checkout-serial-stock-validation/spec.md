## MODIFIED Requirements

### Requirement: Checkout preflight and finalize stock pre-check SHALL validate serial lines from assigned serial context
For serial-required checkout lines, both checkout preflight and finalize pre-check SHALL validate fulfillment using assigned serial records (status, source location allowance, and effective tax context) instead of relying only on line-level `tax_id` quantity buckets. For a bundle line, this validation SHALL apply independently to the parent (if serial-required) and to each bundle component that is serial-required, using each component's own assigned serial context.

#### Scenario: Assigned taxable serial with null line tax passes checks
- **WHEN** a serial-required line has `tax_id=null` and an assigned serial that is active, in an allowed source location, and mapped to taxable stock
- **THEN** preflight and finalize checks MUST treat that line as fulfilled
- **AND** the line index MUST NOT appear in `unfulfilled_lines`.

#### Scenario: Invalid assigned serial fails checks with line-level rejection
- **WHEN** a serial-required line references an assigned serial that is inactive or outside allowed source locations
- **THEN** preflight and finalize checks MUST mark the corresponding line as unfulfilled
- **AND** checkout flow MUST fail with `STOCK_UNAVAILABLE`.

#### Scenario: Serial-required bundle component with insufficient assigned serials fails checks
- **WHEN** a bundle line contains a serial-required component whose assigned-serial count is less than its required quantity
- **THEN** preflight and finalize checks MUST mark the bundle line as unfulfilled
- **AND** checkout flow MUST fail with `STOCK_UNAVAILABLE`.

#### Scenario: Serial-required bundle component with valid assigned serials passes checks
- **WHEN** a bundle line contains a serial-required component whose assigned serials are active, in allowed source locations, and equal in count to its required quantity
- **THEN** preflight and finalize checks MUST treat that component as fulfilled
- **AND** the bundle line MUST NOT be marked unfulfilled due to that component.

### Requirement: Stock pre-check failure diagnostics MUST include actionable line detail
When preflight or finalize fails with `STOCK_UNAVAILABLE`, the system MUST provide structured metadata for each unfulfilled line including line index and product identifier. For a bundle line failing due to a component, the diagnostics MUST also identify the specific unfulfilled component.

#### Scenario: Checkout preflight returns diagnostics for unfulfilled lines
- **WHEN** one or more checkout lines are unfulfilled during preflight
- **THEN** the failure payload MUST include the failing line indices and product identifiers
- **AND** each failing line entry MUST include a machine-readable reason code.

#### Scenario: Checkout finalize returns diagnostics for unfulfilled lines
- **WHEN** one or more checkout lines are unfulfilled during finalize pre-check
- **THEN** the failure payload and/or logged metadata MUST include the failing line indices and product identifiers
- **AND** each failing line entry MUST include a machine-readable reason code.

#### Scenario: Diagnostics identify the specific unfulfilled bundle component
- **WHEN** a bundle line fails preflight or finalize because a specific component has insufficient assigned serials
- **THEN** the failure payload MUST identify the bundle line index, the unfulfilled component's product identifier, and the shortfall in assigned-serial count.

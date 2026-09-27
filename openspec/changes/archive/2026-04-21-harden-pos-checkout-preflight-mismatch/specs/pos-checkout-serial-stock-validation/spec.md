## MODIFIED Requirements

### Requirement: Checkout preflight and finalize stock pre-check SHALL validate serial lines from assigned serial context
For serial-required checkout lines, both checkout preflight and finalize pre-check SHALL validate fulfillment using assigned serial records (status, source location allowance, and effective tax context) instead of relying only on line-level `tax_id` quantity buckets.

#### Scenario: Assigned taxable serial with null line tax passes checks
- **WHEN** a serial-required line has `tax_id=null` and an assigned serial that is active, in an allowed source location, and mapped to taxable stock
- **THEN** preflight and finalize checks MUST treat that line as fulfilled
- **AND** the line index MUST NOT appear in `unfulfilled_lines`.

#### Scenario: Invalid assigned serial fails checks with line-level rejection
- **WHEN** a serial-required line references an assigned serial that is inactive or outside allowed source locations
- **THEN** preflight and finalize checks MUST mark the corresponding line as unfulfilled
- **AND** checkout flow MUST fail with `STOCK_UNAVAILABLE`.

### Requirement: Stock pre-check failure diagnostics MUST include actionable line detail
When preflight or finalize fails with `STOCK_UNAVAILABLE`, the system MUST provide structured metadata for each unfulfilled line including line index and product identifier.

#### Scenario: Checkout preflight returns diagnostics for unfulfilled lines
- **WHEN** one or more checkout lines are unfulfilled during preflight
- **THEN** the failure payload MUST include the failing line indices and product identifiers
- **AND** each failing line entry MUST include a machine-readable reason code.

#### Scenario: Checkout finalize returns diagnostics for unfulfilled lines
- **WHEN** one or more checkout lines are unfulfilled during finalize pre-check
- **THEN** the failure payload and/or logged metadata MUST include the failing line indices and product identifiers
- **AND** each failing line entry MUST include a machine-readable reason code.

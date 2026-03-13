## ADDED Requirements

### Requirement: Finalize stock pre-check SHALL validate serial lines from assigned serial context
For serial-required checkout lines, the system SHALL validate fulfillment using assigned serial records (status, source location allowance, and effective tax context) instead of relying only on line-level `tax_id` quantity buckets.

#### Scenario: Assigned taxable serial with null line tax passes pre-check
- **WHEN** a serial-required line has `tax_id=null` and an assigned serial that is active, in an allowed source location, and mapped to taxable stock
- **THEN** finalize pre-check MUST treat that line as fulfilled
- **AND** the line index MUST NOT appear in `unfulfilled_lines`.

#### Scenario: Invalid assigned serial fails pre-check with line-level rejection
- **WHEN** a serial-required line references an assigned serial that is inactive or outside allowed source locations
- **THEN** finalize pre-check MUST mark the corresponding line as unfulfilled
- **AND** checkout MUST fail with `STOCK_UNAVAILABLE`.

### Requirement: Stock pre-check failure diagnostics MUST include actionable line detail
When finalize fails with `STOCK_UNAVAILABLE`, the system MUST provide structured metadata for each unfulfilled line including line index and product identifier.

#### Scenario: Checkout returns diagnostics for unfulfilled lines
- **WHEN** one or more checkout lines are unfulfilled during pre-check
- **THEN** the failure payload and/or logged metadata MUST include the failing line indices and product identifiers
- **AND** each failing line entry MUST include a machine-readable reason code.

### Requirement: Non-serial pre-check semantics SHALL remain unchanged
The serial-aware validation path MUST NOT change fulfillment behavior for non-serial lines that continue to use quantity-based stock buckets.

#### Scenario: Non-serial insufficient stock remains unfulfilled
- **WHEN** a non-serial line requests quantity greater than available stock in allowed locations for its effective tax bucket
- **THEN** finalize pre-check MUST report that line as unfulfilled under the existing quantity-based rules.

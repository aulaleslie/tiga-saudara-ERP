## MODIFIED Requirements

### Requirement: Checkout preflight and finalize stock pre-check SHALL validate serial lines from assigned serial context
For serial-required checkout lines, both checkout preflight and finalize pre-check SHALL validate fulfillment using assigned serial records (status, source location allowance, and effective tax context) instead of relying only on line-level `tax_id` quantity buckets. For a bundle line, this validation SHALL apply independently to the parent (if serial-required) and to each bundle component that is serial-required, using each component's own assigned serial context. Matching an assigned serial value against `product_serial_numbers` SHALL be case-insensitive: the comparison SHALL succeed regardless of whether the assigned serial and the stored serial differ only in letter case.

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

#### Scenario: Assigned serial differing only in case from the stored serial passes checks
- **WHEN** a serial-required line has an assigned serial value that differs only in letter case from an active, in-stock, unassigned `product_serial_numbers` record (e.g. assigned as `nxeftsn001140029712n00` while stored as `NXEFTSN001140029712N00`)
- **THEN** preflight and finalize checks MUST treat that line as fulfilled using the matched record
- **AND** the line index MUST NOT appear in `unfulfilled_lines`
- **AND** this MUST hold without requiring any correction to previously stored cart or line-serial data.

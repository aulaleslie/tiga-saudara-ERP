## MODIFIED Requirements

### Requirement: Checkout preflight and finalize stock pre-check SHALL validate serial lines from assigned serial context
For serial-required checkout lines, both checkout preflight and finalize pre-check SHALL validate fulfillment using assigned serial records (lifecycle status, physical condition, dispatch/return state, source location allowance, and effective tax context) instead of relying only on line-level `tax_id` quantity buckets. A sellable serial MUST be active-compatible, not broken, undispatched, and not in a return process. `MISSING`, `SOLD`, `RETURNED`, `RETURN_IN_PROCESS`, and legacy `BROKEN` serials MUST NOT fulfill a sale. For a bundle line, this validation SHALL apply independently to the parent (if serial-required) and to each serial-required bundle component using each component's assigned serial context. Matching an assigned serial value against `product_serial_numbers` SHALL be case-insensitive.

#### Scenario: Assigned taxable sellable serial with null line tax passes checks
- **WHEN** a serial-required line has `tax_id=null` and an assigned serial that is sellable, in an allowed source location, and mapped to taxable stock
- **THEN** preflight and finalize checks MUST treat that line as fulfilled
- **AND** the line index MUST NOT appear in `unfulfilled_lines`

#### Scenario: Missing assigned serial fails checks
- **WHEN** a serial-required line references an assigned serial whose current status is `MISSING`
- **THEN** preflight and finalize checks MUST mark the corresponding line as unfulfilled
- **AND** checkout flow MUST fail with `STOCK_UNAVAILABLE`

#### Scenario: Active broken assigned serial fails checks
- **WHEN** a serial-required line references an `ACTIVE` serial with `is_broken=true`
- **THEN** preflight and finalize checks MUST mark the corresponding line as unfulfilled
- **AND** checkout flow MUST fail with `STOCK_UNAVAILABLE`

#### Scenario: Invalid assigned serial fails checks with line-level rejection
- **WHEN** a serial-required line references an assigned serial that is unavailable or outside allowed source locations
- **THEN** preflight and finalize checks MUST mark the corresponding line as unfulfilled
- **AND** checkout flow MUST fail with `STOCK_UNAVAILABLE`

#### Scenario: Serial-required bundle component with insufficient sellable serials fails checks
- **WHEN** a bundle line contains a serial-required component whose sellable assigned-serial count is less than its required quantity
- **THEN** preflight and finalize checks MUST mark the bundle line as unfulfilled
- **AND** checkout flow MUST fail with `STOCK_UNAVAILABLE`

#### Scenario: Serial-required bundle component with valid sellable serials passes checks
- **WHEN** a bundle line contains a serial-required component whose assigned serials are sellable, in allowed source locations, and equal in count to its required quantity
- **THEN** preflight and finalize checks MUST treat that component as fulfilled
- **AND** the bundle line MUST NOT be marked unfulfilled due to that component

#### Scenario: Assigned serial differing only in case from the stored serial passes checks
- **WHEN** a serial-required line has an assigned serial value that differs only in letter case from a sellable `product_serial_numbers` record
- **THEN** preflight and finalize checks MUST treat that line as fulfilled using the matched record
- **AND** the line index MUST NOT appear in `unfulfilled_lines`
- **AND** this MUST hold without requiring any correction to previously stored cart or line-serial data

## ADDED Requirements

### Requirement: Every POS serial entry and posting boundary SHALL enforce sellability
POS serial suggestions, exact serial scans, ordinary cart assignment, bundle-component assignment, preflight, and final locked posting SHALL all enforce the canonical sellable predicate. Final posting SHALL re-read and lock authoritative serial state and SHALL roll back atomically if a previously assigned serial became unavailable or broken.

#### Scenario: Missing serial is absent from suggestions and exact scan
- **WHEN** a serial has `status=MISSING`
- **THEN** POS does not return it in serial suggestions
- **AND** exact serial scanning reports it as unavailable without adding it to the cart

#### Scenario: Active broken serial is absent from sale selection
- **WHEN** a serial has `status=ACTIVE` and `is_broken=true`
- **THEN** POS suggestions, exact scan, direct line assignment, and bundle-component assignment reject it

#### Scenario: Serial becomes broken after assignment
- **WHEN** a good active serial is assigned to a cart and becomes broken before locked final posting
- **THEN** final posting fails atomically
- **AND** no Sale, dispatch, stock, serial, transaction, payment, or checkout posting effect remains

#### Scenario: Serial becomes missing after assignment
- **WHEN** a good active serial is assigned to a cart and becomes `MISSING` before locked final posting
- **THEN** final posting fails atomically with serial-specific diagnostics
- **AND** no partial posting effect remains

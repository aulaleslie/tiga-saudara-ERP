# pos-checkout-serial-stock-validation Specification

## Purpose
TBD - created by archiving change fix-pos-stock-unavailable-mixed-business-cart. Update Purpose after archive.
## Requirements
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

### Requirement: Non-serial taxable pre-check SHALL apply owner-priority allocation
For non-serial taxable lines, finalize stock pre-check MUST allocate across allowed locations by owner-priority order: source owners with `is_pkp=false` first, then source owners with `is_pkp=true`. Within each owner-priority group, configured sales-location order SHALL remain deterministic.

#### Scenario: Non-serial taxable line prefers non-PKP source before PKP source
- **WHEN** a taxable non-serial line can be fulfilled from both non-PKP and PKP source owners
- **THEN** finalize pre-check MUST allocate required quantity from non-PKP-owned locations first
- **AND** only allocate from PKP-owned locations for any remaining quantity.

#### Scenario: Owner-priority preserves configured location ordering within each priority group
- **WHEN** multiple allowed locations belong to the same owner-priority group (all non-PKP or all PKP)
- **THEN** finalize pre-check MUST consume stock following configured sales-location order within that group
- **AND** identical inputs MUST produce deterministic allocation output.

### Requirement: Finalize pre-check rejects assigned serials that entered PENDING dispatch
When a serial was assigned to a POS cart line but subsequently entered a PENDING dispatch before checkout finalization, the finalize pre-check SHALL reject that serial and fail the checkout.

#### Scenario: Assigned serial enters PENDING dispatch before finalize
- **WHEN** serial `TNC202604040001` was assigned to a POS cart line while it was available
- **AND** between assignment and checkout finalization, a dispatch referencing `TNC202604040001` is created with status `PENDING`
- **THEN** finalize pre-check MUST mark that line as unfulfilled
- **AND** checkout MUST fail with `STOCK_UNAVAILABLE`
- **AND** the failure metadata MUST indicate the serial is in a pending dispatch

#### Scenario: Assigned serial with no pending dispatch passes pre-check
- **WHEN** serial `TNC202604040001` is assigned to a POS cart line
- **AND** no PENDING dispatch references that serial
- **AND** the serial's status is `ACTIVE` and `dispatch_detail_id` is NULL
- **THEN** finalize pre-check MUST treat the line as fulfilled

### Requirement: Validated serial allocations SHALL be retained through final posting
When finalize pre-check validates a serial-tracked checkout line using assigned serial context, the resulting allocation SHALL remain available to the final posting adapter after any split planning step. A line that passed serial stock validation MUST NOT later fail solely because its parent allocation was omitted from grouped posting context.

#### Scenario: Valid serial allocation posts after split planning
- **WHEN** a serial-tracked checkout line has assigned serials that are active, allowed, and backed by sufficient stock bucket quantity
- **AND** split posting is enabled
- **THEN** final posting receives a non-empty allocation for that serial line
- **AND** checkout does not fail with `STOCK_UNAVAILABLE` due to missing parent allocation

#### Scenario: Serial lifecycle updates use grouped allocation serials
- **WHEN** split posting finalizes a serial-tracked checkout line
- **THEN** each assigned serial posted in a grouped line is marked sold
- **AND** each sold serial is linked to the dispatch detail created for its grouped line


# pos-checkout-serial-stock-validation Specification

## Purpose
TBD - created by archiving change fix-pos-stock-unavailable-mixed-business-cart. Update Purpose after archive.
## Requirements
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


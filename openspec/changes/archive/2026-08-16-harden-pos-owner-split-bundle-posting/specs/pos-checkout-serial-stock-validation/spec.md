## REMOVED Requirements

### Requirement: Non-serial taxable pre-check SHALL apply owner-priority allocation
**Reason**: Physical fulfillment now follows exact configured location order; owner PKP status is a tax concern and no longer reorders stock sources.

**Migration**: Use the replacement configured-location-priority requirement below for both taxable and non-taxable stock-managed non-serial lines.

## ADDED Requirements

### Requirement: Non-serial stock pre-check SHALL apply configured-location priority
For stock-managed non-serial lines, checkout preflight and finalize MUST allocate across enabled POS sales locations in exact configured order. Within each location, existing stock-bucket availability rules SHALL remain deterministic, but source settings MUST NOT be reordered by PKP status.

#### Scenario: Non-serial line follows configured order across owners
- **WHEN** a stock-managed non-serial line can be fulfilled from multiple configured locations owned by different settings
- **THEN** preflight and finalize SHALL consume available stock from the earliest configured location first
- **AND** they SHALL move to later locations only for the remaining quantity

#### Scenario: PKP status does not reorder locations
- **WHEN** a later configured location belongs to a non-PKP setting and an earlier configured location belongs to a PKP setting
- **THEN** the earlier location SHALL retain fulfillment priority
- **AND** tax policy SHALL NOT change the physical location order

## MODIFIED Requirements

### Requirement: Checkout preflight and finalize stock pre-check SHALL validate serial lines from assigned serial context
For serial-required checkout lines, both checkout preflight and finalize SHALL validate fulfillment using assigned serial records. Each selected serial's persisted `location_id` SHALL be its authoritative source location, and the setting owning that location SHALL be the authoritative Split Sale owner for that serial quantity.

#### Scenario: Assigned serial determines source owner
- **WHEN** a valid assigned serial belongs to an enabled source location owned by Setting B during a Setting A POS transaction
- **THEN** its allocation SHALL use that serial's persisted location
- **AND** its Split Sale ownership SHALL resolve to Setting B from the location record

#### Scenario: Multiple assigned serial locations create separate fulfillment groups
- **WHEN** one serial-required line contains valid assigned serials from locations owned by different settings
- **THEN** each serial SHALL remain in the group for its own persisted location and owner setting
- **AND** no general location-priority allocation SHALL relocate the selected serials

#### Scenario: Invalid assigned serial fails checks with line-level rejection
- **WHEN** a serial-required line references an assigned serial that is inactive, lacks a location, belongs to another product, or resides outside enabled source locations
- **THEN** preflight and finalize SHALL mark the corresponding line as unfulfilled
- **AND** checkout SHALL fail with actionable `STOCK_UNAVAILABLE` details

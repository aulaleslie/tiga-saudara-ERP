## MODIFIED Requirements

### Requirement: Price Override MUST Follow Role-Aware Supervisory Governance
The POS system SHALL allow direct price override only for authorized manager-level users and SHALL require approval workflow for non-authorized users. The system SHALL accept zero as a valid override price.

#### Scenario: Non-authorized user requests price change
- **WHEN** a Floor Staff or Cashier Staff user attempts to alter sales price without direct override permission
- **THEN** the system MUST create approval request and MUST NOT apply new price until approval is confirmed and executed

#### Scenario: Authorized manager overrides price directly
- **WHEN** a Store Manager user with price override permission updates item sales price
- **THEN** the system MUST apply the new price immediately and MUST record audit metadata for actor and timestamp

#### Scenario: User sets price to zero
- **WHEN** a user submits a price override with unit_price equal to 0
- **THEN** the system MUST accept the value as valid and process it through the normal authorization flow
- **AND** the system MUST NOT reject the request with a validation error

#### Scenario: User submits negative price
- **WHEN** a user submits a price override with unit_price less than 0
- **THEN** the system MUST reject the request with a validation error

## ADDED Requirements

### Requirement: Cart Snapshot MUST Expose Requested Unit Price From PRICE_OVERRIDE Approval Payloads
The cart snapshot builder SHALL extract `unit_price` from PRICE_OVERRIDE approval request payloads and expose it as `requested_unit_price` in the line's `pending_approvals` array.

#### Scenario: PRICE_OVERRIDE approval includes requested_unit_price in snapshot
- **WHEN** a cart line has a pending or approved PRICE_OVERRIDE approval request with `unit_price` in its request payload
- **THEN** the snapshot's `pending_approvals` entry for that line MUST include `requested_unit_price` with the value from the request payload

#### Scenario: Non-PRICE_OVERRIDE approvals remain unchanged
- **WHEN** a cart line has a pending QTY_REDUCE approval request
- **THEN** the snapshot's `pending_approvals` entry MUST include `requested_qty` as before
- **AND** MUST NOT include `requested_unit_price`

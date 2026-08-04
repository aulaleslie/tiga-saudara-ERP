## ADDED Requirements

### Requirement: Cart-total overrides MUST follow role-aware supervisory governance
The POS system SHALL allow a user with direct `pos.overrides.total-price` permission to apply a cart-total override immediately and SHALL require the existing supervisory request, approval, one-time-token, and final-confirmation flow for a user who lacks that permission, unless the user has the Super Admin role.

#### Scenario: Non-authorized user requests a cart-total change
- **WHEN** a cashier without `pos.overrides.total-price` submits a valid target total
- **THEN** the system MUST create a `TOTAL_PRICE_OVERRIDE` approval request
- **AND** MUST NOT alter cart totals or row pricing until approval is confirmed and executed

#### Scenario: Authorized user applies a cart-total change
- **WHEN** a user with `pos.overrides.total-price` submits a valid target total
- **THEN** the system MUST apply the cart-total override without creating an approval request
- **AND** MUST record the direct action in the POS approval audit trail

#### Scenario: Supervisor approves a cart-total change
- **WHEN** a supervisor with `pos.supervisor.approval` and `pos.overrides.total-price` approves a pending `TOTAL_PRICE_OVERRIDE` request
- **THEN** the system MUST issue a one-time execution token for that request
- **AND** the requester MUST explicitly confirm before the cart is altered

### Requirement: Cart-total approval state SHALL be exposed independently of line actions
The cart snapshot SHALL expose pending or approved `TOTAL_PRICE_OVERRIDE` requests as cart-level approval state and MUST NOT attach that state to individual cart rows.

#### Scenario: Cart-total request coexists with a line-price request
- **WHEN** a cart has a pending total-price override and a pending line `PRICE_OVERRIDE`
- **THEN** the cart-level total-price control MUST reflect only the total-price request state
- **AND** the line price control MUST reflect only the line-price request state

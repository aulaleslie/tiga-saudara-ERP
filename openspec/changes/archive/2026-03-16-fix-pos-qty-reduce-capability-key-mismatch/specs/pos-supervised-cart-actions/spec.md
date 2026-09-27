## ADDED Requirements

### Requirement: Quantity-Reduction Capability Contract MUST Be Explicit And Consistent
The POS role capability payload SHALL expose an explicit `can_reduce_quantity` flag and MUST keep it consistent with direct reduce permission metadata.

#### Scenario: Capability payload includes canonical reduce key
- **WHEN** POS sell page capabilities are generated for an authenticated user
- **THEN** the payload MUST include `can_reduce_quantity` as a boolean value

#### Scenario: Canonical key mirrors direct reduce permission
- **WHEN** capability payload includes both `can_reduce_quantity` and `direct_permissions.qty_reduce`
- **THEN** both values MUST represent the same effective authorization for `pos.cart.line.reduce`

#### Scenario: Frontend resolves partial payload restrictively
- **WHEN** capability payload is missing `can_reduce_quantity`
- **THEN** the frontend MUST fallback to `direct_permissions.qty_reduce`, and if unavailable MUST treat quantity reduction as non-privileged

## MODIFIED Requirements

### Requirement: Restricted Cart Mutations MUST Require Supervisory Approval For Non-Authorized Users
The POS system SHALL require supervisory approval before executing `clear cart`, `remove line`, or `reduce quantity` actions when the acting user lacks direct permission for the action, including cases where capability payload shape is partial.

#### Scenario: Non-authorized user requests clear cart
- **WHEN** a Floor Staff or Cashier Staff user attempts to clear the cart without direct clear permission
- **THEN** the system MUST create an approval request and MUST NOT clear the cart immediately

#### Scenario: Non-authorized user requests line removal
- **WHEN** a Floor Staff or Cashier Staff user attempts to remove an item line without direct remove permission
- **THEN** the system MUST create an approval request and MUST NOT remove the line immediately

#### Scenario: Non-authorized user requests quantity reduction
- **WHEN** a Floor Staff or Cashier Staff user submits a lower quantity than current quantity without direct reduce permission
- **THEN** the system MUST create an approval request and MUST NOT apply the reduced quantity immediately

#### Scenario: Pending quantity reduction remains visible for non-authorized user
- **WHEN** a non-authorized user has a `PENDING` `QTY_REDUCE` request on a cart line
- **THEN** the cart row MUST render a follow-up `Periksa Persetujuan` control bound to that request

#### Scenario: Authorized manager executes directly
- **WHEN** a Store Manager user performs clear cart, remove line, or reduce quantity with required direct permission
- **THEN** the system MUST execute the action immediately without creating approval request

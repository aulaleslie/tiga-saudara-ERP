## MODIFIED Requirements

### Requirement: Restricted Cart Mutations MUST Require Supervisory Approval For Non-Authorized Users
The POS system SHALL require supervisory approval before executing `clear cart`, `remove line`, or `reduce quantity` actions when the acting user lacks direct permission for the action, UNLESS the user has Super Admin role.

#### Scenario: Super Admin executes cart mutations directly without approval
- **WHEN** a Super Admin user performs clear cart, remove line, or reduce quantity
- **THEN** the system MUST execute the action immediately without creating an approval request
- **AND** the authorization MUST succeed based solely on Super Admin role

#### Scenario: Super Admin with Super Admin role does not require pos.cart.line.reduce permission
- **WHEN** a Super Admin user reduces quantity despite the role lacking the explicit `pos.cart.line.reduce` permission
- **THEN** the system MUST execute the quantity reduction immediately
- **AND** the user MUST NOT be prompted for supervisory approval

#### Scenario: Non-authorized user requests quantity reduction
- **WHEN** a Floor Staff or Cashier Staff user submits a lower quantity than current quantity without direct reduce permission and without Super Admin role
- **THEN** the system MUST create an approval request and MUST NOT apply the reduced quantity immediately

#### Scenario: Authorized manager executes directly
- **WHEN** a Store Manager user with pos.cart.line.reduce permission reduces quantity
- **THEN** the system MUST execute the action immediately without creating approval request


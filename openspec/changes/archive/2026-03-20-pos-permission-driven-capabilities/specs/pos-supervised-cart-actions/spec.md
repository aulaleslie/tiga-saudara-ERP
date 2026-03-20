## MODIFIED Requirements

### Requirement: Restricted Cart Mutations MUST Require Supervisory Approval For Non-Authorized Users
The POS system SHALL require supervisory approval before executing `clear cart`, `remove line`, or `reduce quantity` actions when the acting user lacks the direct permission for that action. Direct execution SHALL be determined by explicit permissions, not by named roles.

#### Scenario: User without direct clear permission requests clear cart
- **WHEN** a user attempts to clear the cart without `pos.cart.clear`
- **THEN** the system MUST create an approval request
- **AND** the cart MUST NOT be cleared immediately

#### Scenario: User without direct remove permission requests line removal
- **WHEN** a user attempts to remove an item line without `pos.cart.line.remove`
- **THEN** the system MUST create an approval request
- **AND** the line MUST NOT be removed immediately

#### Scenario: User without direct reduce permission requests quantity reduction
- **WHEN** a user submits a lower quantity than current quantity without `pos.cart.line.reduce`
- **THEN** the system MUST create an approval request
- **AND** the reduced quantity MUST NOT be applied immediately

#### Scenario: User with direct permission executes immediately
- **WHEN** a user performs clear cart, remove line, or reduce quantity with the required direct permission
- **THEN** the system MUST execute the action immediately without creating an approval request

### Requirement: Price Override MUST Follow Role-Aware Supervisory Governance
The POS system SHALL allow direct price override whenever the acting user has `pos.overrides.price`, and SHALL require approval workflow whenever that permission is absent. Role names MUST NOT suppress or grant direct override authority.

#### Scenario: User without direct override permission requests price change
- **WHEN** a user attempts to lower or alter sales price without `pos.overrides.price`
- **THEN** the system MUST create an approval request
- **AND** the new price MUST NOT be applied until approval is confirmed and executed

#### Scenario: User with direct override permission updates price directly
- **WHEN** a user with `pos.overrides.price` updates an item sales price
- **THEN** the system MUST apply the new price immediately
- **AND** the system MUST record audit metadata for actor and timestamp

#### Scenario: Role name does not block explicit override permission
- **WHEN** a user's role name would previously have been treated as non-manager
- **AND** that user has `pos.overrides.price`
- **THEN** the system MUST still allow direct price override without requiring approval

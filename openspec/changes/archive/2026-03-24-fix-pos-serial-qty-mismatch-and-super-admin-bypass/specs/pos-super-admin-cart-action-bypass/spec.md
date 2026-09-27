# pos-super-admin-cart-action-bypass Specification

## ADDED Requirements

### Requirement: Super Admin Users MUST Bypass Supervisory Approval For Quantity Reduction
The POS system SHALL recognize users with the Super Admin role and allow them to reduce cart line quantity without requiring supervisory approval or approval tokens.

#### Scenario: Super Admin reduces quantity without approval token
- **WHEN** a user with Super Admin role attempts to reduce cart line quantity from 2 to 1
- **THEN** the system MUST NOT require an approval token
- **AND** the quantity MUST be updated immediately to 1
- **AND** no approval request MUST be created

#### Scenario: Super Admin is not blocked by missing pos.cart.line.reduce permission
- **WHEN** a Super Admin user reduces quantity despite not having the explicit `pos.cart.line.reduce` permission
- **THEN** the system MUST allow the reduction
- **AND** the authorization MUST succeed based on Super Admin role alone

#### Scenario: Non-Super-Admin without permission still requires approval
- **WHEN** a non-Super-Admin user without `pos.cart.line.reduce` permission attempts to reduce quantity
- **THEN** the system MUST still require supervisory approval or approval token
- **AND** the behavior MUST remain unchanged from the existing flow

### Requirement: Super Admin MUST Be Detected By Role Name In Authorization Check
The authorization service SHALL check for the Super Admin role by name and grant automatic authorization without relying on explicit permissions.

#### Scenario: Super Admin role is case-insensitive match
- **WHEN** a user with role name "Super Admin" calls an authorization check for quantity reduction
- **THEN** the authorization MUST succeed
- **AND** the system MUST treat this as a direct permission grant

#### Scenario: Super Admin grant does not consume approval tokens
- **WHEN** a Super Admin user provides both role-based authorization and an approval token
- **THEN** the system MUST NOT consume the approval token
- **AND** the token MUST remain available for other requests


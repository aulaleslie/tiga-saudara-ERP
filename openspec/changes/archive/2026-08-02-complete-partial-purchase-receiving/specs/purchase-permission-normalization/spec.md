## ADDED Requirements

### Requirement: Supplier-shortfall completion uses a canonical dedicated permission
The system SHALL define `purchases.receive.complete_shortfall` in the canonical purchase receiving permission catalog with a clear role-management label, and SHALL use that same permission intent for all shortfall-completion UI actions and endpoints.

#### Scenario: Permission appears in receiving role management
- **WHEN** an administrator manages a role's purchase receiving permissions
- **THEN** the role form SHALL present the supplier-shortfall completion permission separately from receive and receive-approval permissions

#### Scenario: UI and endpoint use the same authority
- **WHEN** a user lacks the supplier-shortfall completion permission
- **THEN** the action SHALL be hidden from the normal purchase list, purchase detail, and receiving history
- **AND** direct preview and completion requests SHALL be forbidden

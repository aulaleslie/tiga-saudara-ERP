## Purpose

Expand the canonical sales permission catalog to support post-dispatch monetary editing authority.

## ADDED Requirements

### Requirement: Sales post-dispatch monetary edit permission is canonical and assignable
The system SHALL define `sales.dispatched.monetary.edit` in the canonical sales permission catalog while retaining `sales.approved.edit`. Role-management UI and permission synchronization SHALL expose both keys as distinct authorities.

#### Scenario: Roles can receive post-dispatch monetary edit authority
- **WHEN** an administrator opens role management after permissions are synchronized
- **THEN** the administrator SHALL be able to assign `sales.dispatched.monetary.edit`
- **AND** the existing `sales.approved.edit` permission SHALL remain available

### Requirement: Sales lifecycle edit checks use defined permissions
The system SHALL use `sales.approved.edit` for full edits of approved undispatched Sales and `sales.dispatched.monetary.edit` for monetary-only edits of dispatched Sales, in addition to ordinary `sales.edit` authority.

#### Scenario: Sales authorization layers agree
- **WHEN** a Sale edit action is evaluated in the UI, Livewire component, controller, or persistence service
- **THEN** every layer SHALL enforce the permission applicable to the persisted Sale lifecycle status

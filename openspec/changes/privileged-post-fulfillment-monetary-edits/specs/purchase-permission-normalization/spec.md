## ADDED Requirements

### Requirement: Purchase lifecycle edit permissions are canonical and assignable
The system SHALL define `purchases.approved.edit` and `purchases.received.monetary.edit` in the canonical purchase permission catalog, alongside the existing `purchases.received.correct` permission. Role-management UI and permission synchronization SHALL expose all three keys as distinct authorities.

#### Scenario: Roles can receive approved and post-receipt edit authority
- **WHEN** an administrator opens role management after permissions are synchronized
- **THEN** the administrator SHALL be able to assign `purchases.approved.edit` and `purchases.received.monetary.edit`
- **AND** the existing `purchases.received.correct` permission SHALL remain available and unchanged

### Requirement: Purchase lifecycle edit checks use defined permissions
The system SHALL use `purchases.approved.edit` for full edits of approved unreceived Purchases and `purchases.received.monetary.edit` for monetary-only edits of received Purchases, in addition to ordinary `purchases.update` authority.

#### Scenario: Purchase authorization layers agree
- **WHEN** a Purchase edit action is evaluated in the UI, Livewire component, controller, or persistence service
- **THEN** every layer SHALL enforce the permission applicable to the persisted Purchase lifecycle status

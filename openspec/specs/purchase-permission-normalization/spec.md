## Purpose

Establish a canonical permission catalog for purchase and purchase-return lifecycle actions with consistent verb taxonomy and authorization enforcement.

## Requirements

### Requirement: Canonical Purchase Permission Catalog
The system SHALL define and maintain a canonical permission catalog for purchase and purchase-return lifecycle actions using a consistent verb taxonomy across role configuration and enforcement sites.

#### Scenario: Canonical keys are defined for both domains
- **WHEN** maintainers review the permission catalog for purchase-related domains
- **THEN** purchase and purchase-return lifecycle permissions use consistent action verbs and domain prefixes
- **THEN** each key used by authorization checks exists in the catalog

### Requirement: Authorization Checks MUST Reference Defined Keys
The system MUST ensure route middleware, controller gate checks, Livewire checks, and Blade `@can` conditions for purchase and purchase-return lifecycle actions reference only defined permission keys.

#### Scenario: Gated route uses defined permission
- **WHEN** a protected purchase or purchase-return route is evaluated
- **THEN** its authorization check references a permission key defined in the permission catalog
- **THEN** undefined keys are not used in runtime authorization

#### Scenario: UI and backend permission checks align
- **WHEN** an action button is visible in purchase or purchase-return views
- **THEN** the backing action endpoint enforces the same permission intent
- **THEN** users are not presented with actions they can never execute due to mismatched permissions

### Requirement: Role Management Grouping MUST Be Consistent for Purchase Domains
The system SHALL present purchase and purchase-return permissions in role create/edit screens with consistent grouping and labels derived from the canonical catalog.

#### Scenario: Role form displays normalized groups
- **WHEN** an admin opens role create or edit
- **THEN** purchase and purchase-return permissions appear in stable, domain-consistent groups
- **THEN** duplicate or ambiguous action labels are not shown for the same intent

### Requirement: Legacy and Unused Purchase Permission Keys MUST Be Retired Safely
The system MUST support safe retirement of unused or unrelated purchase permission keys through explicit migration mapping before cleanup.

#### Scenario: Legacy role assignment is preserved during rename
- **WHEN** permission keys are renamed or consolidated in purchase domains
- **THEN** existing role assignments are remapped to canonical keys before legacy keys are removed
- **THEN** post-migration roles retain equivalent access intent

#### Scenario: Dead permission keys are removed
- **WHEN** maintainers run permission synchronization after cleanup
- **THEN** unused and unrelated purchase-domain permission keys are pruned from persisted permissions
- **THEN** no active purchase authorization path depends on removed keys

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

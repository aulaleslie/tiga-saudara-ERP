# pos-permission-governance Specification

## Purpose
TBD - created by archiving change redesign-pos-role-permission-model. Update Purpose after archive.
## Requirements
### Requirement: POS permission registry SHALL remain in parity with runtime authorization
Every POS permission used by runtime route middleware, request authorization, controller checks, service policies, or menu visibility SHALL be represented in the centralized POS permission registry. Every POS permission exposed in the registry SHALL either be enforced by supported runtime behavior or explicitly marked as deprecated with a documented replacement or retirement path.

#### Scenario: Runtime permission is missing from registry
- **WHEN** a POS runtime code path checks a permission that is not present in the centralized permission registry
- **THEN** the change MUST add that permission to the registry before the runtime behavior is considered complete

#### Scenario: Registry permission is no longer supported
- **WHEN** a POS permission remains in the registry but no supported runtime behavior relies on it
- **THEN** the system MUST classify it as deprecated or remove it from the supported role-assignment surface
- **AND** the change MUST document the migration or replacement path

### Requirement: POS role assignment SHALL prefer supported bundles over arbitrary flat composition
The role-management surface for POS SHALL present supported POS bundles and grouped POS capability clusters as the primary assignment model. Raw POS permissions MAY still exist for exceptions, but the system SHALL document which permissions belong to supported bundles and which are exceptional overrides.

#### Scenario: Super Admin assigns standard cashier access
- **WHEN** Super Admin configures a POS cashier role
- **THEN** the role-management surface MUST provide a supported cashier bundle or equivalent grouped guidance
- **AND** the resulting assignment MUST align with the documented cashier access matrix

#### Scenario: Super Admin assigns custom exception access
- **WHEN** Super Admin grants a POS exception outside the supported default bundle
- **THEN** the system MUST preserve the explicit permission assignment
- **AND** the role-management model MUST still identify that assignment as a custom override rather than a default bundle

### Requirement: Deprecated POS permissions SHALL NOT silently affect supported role behavior
Permissions that have been deprecated or superseded SHALL NOT remain silently authoritative for supported POS bundles. If a deprecated permission is retained temporarily for migration safety, the system SHALL document that it is transitional and SHALL NOT require admins to depend on it for the supported owner, manager, cashier, or floor-staff bundles.

#### Scenario: Deprecated permission remains assigned on a live role
- **WHEN** a live POS role still contains a deprecated POS permission during migration
- **THEN** the system MUST provide a documented supported replacement or safe retirement path
- **AND** the supported bundle model MUST remain understandable without relying on that deprecated permission

### Requirement: POS SHALL define a debt-checkout permission governing direct vs. approval-required behavior
The POS permission registry SHALL include a `pos.checkout.debt` permission that governs finishing a transaction as debt. Users holding it directly SHALL self-authorize the debt path; users lacking it SHALL require supervisory approval; Super Admin SHALL bypass. The permission SHALL be registered in parity with runtime authorization checks.

#### Scenario: Direct permission holder self-authorizes
- **WHEN** a user holds `pos.checkout.debt` directly and finishes a transaction as debt
- **THEN** the system MUST authorize the action without an approval request

#### Scenario: Missing permission requires approval
- **WHEN** a user lacks `pos.checkout.debt` and attempts to finish as debt
- **THEN** the system MUST require supervisory approval and MUST report an approval-required outcome

#### Scenario: Debt-checkout permission is registered in parity
- **WHEN** the POS permission registry is validated against runtime authorization
- **THEN** `pos.checkout.debt` MUST be present in the registry and map to the debt-checkout authorization check

### Requirement: POS SHALL govern both row overrides through one supported direct permission
The POS permission registry SHALL expose `pos.overrides.price` as the single supported permission for direct authoritative row unit-price and row-total changes. Users holding it SHALL self-authorize both actions, users lacking it SHALL require supervisory approval for both actions, and Super Admin SHALL bypass explicit permission checks. The permission MUST remain in parity with runtime authorization and supported role bundles.

#### Scenario: Direct permission holder self-authorizes both actions
- **WHEN** a user holds `pos.overrides.price` and submits a valid unit price or a valid row total for a valid row target
- **THEN** the system MUST authorize the action without an approval request

#### Scenario: Missing permission requires approval for both actions
- **WHEN** a user lacks `pos.overrides.price` and submits a valid unit price or a valid row total for a valid row target
- **THEN** the system MUST require supervisory approval
- **AND** MUST report an approval-required outcome

#### Scenario: Permission registry remains in parity
- **WHEN** runtime authorization and supported role bundles are validated
- **THEN** `pos.overrides.price` MUST be represented in the centralized registry and the appropriate exception and manager capability surfaces
- **AND** it MUST map to both active override action types

### Requirement: Retired cart-total permission SHALL NOT authorize active POS behavior
The legacy `pos.overrides.total-price` cart-wide permission MAY remain stored temporarily for migration or historical role compatibility, but it MUST be marked deprecated, MUST be absent from active capability bundles and assignment surfaces, and MUST NOT authorize any new cart-wide mutation.

#### Scenario: Existing role retains legacy permission
- **WHEN** a live role still has `pos.overrides.total-price`
- **THEN** that assignment MUST NOT expose or authorize a cart-wide “Ubah Total” action
- **AND** administrators MUST have a documented replacement or retirement path

#### Scenario: Deprecated permission is absent from active bundles
- **WHEN** active POS capability bundles are resolved
- **THEN** `pos.overrides.total-price` MUST NOT appear as an active capability


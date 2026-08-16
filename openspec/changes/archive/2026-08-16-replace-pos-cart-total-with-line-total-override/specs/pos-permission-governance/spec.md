## ADDED Requirements

### Requirement: POS SHALL govern both row overrides through one supported direct permission
The POS permission registry SHALL expose `pos.overrides.price` as the single supported permission for direct authoritative row unit-price and row-total changes. Users holding it SHALL self-authorize both actions, users lacking it SHALL require supervisory approval for both actions, and Super Admin SHALL bypass explicit permission checks. The permission MUST remain in parity with runtime authorization and supported role bundles.

#### Scenario: Direct permission holder self-authorizes both actions
- **WHEN** a user holds `pos.overrides.price` and submits a valid unit price or a valid row total for a valid row target
- **THEN** the system MUST authorize the action without an approval request

#### Scenario: Missing permission requires approval for both actions
- **WHEN** a user lacks `pos.overrides.price` and submits a valid unit price or a valid row total for a valid row target
- **THEN** the system MUST require supervisory approval
- **AND** MUST report the standard approval-required outcome

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

## REMOVED Requirements

### Requirement: POS SHALL define a total-price-override permission governing direct versus supervised behavior
**Reason**: The permission governs the retired cart-wide operation and must not remain an active supported capability.

**Migration**: Assign `pos.overrides.price` to roles allowed to bypass approval for either row override; all other users use supervisory approval. Preserve legacy assignments only as non-authoritative migration data if immediate deletion is unsafe.

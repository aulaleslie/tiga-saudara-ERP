# pos-sale-location-onboarding Specification

## Purpose
TBD - created by archiving change harden-pos-cash-and-location-integrity. Update Purpose after archive.
## Requirements
### Requirement: A new location SHALL be enabled for its owning business only
When a location is created, the system SHALL create or enable its sale-location assignment for the business identified by the location's `setting_id`. The creation flow MUST NOT automatically enable the new location for unrelated businesses.

#### Scenario: Standard location creation
- **WHEN** an authorized user creates a location owned by the active business
- **THEN** the system SHALL create an enabled sale-location assignment for that business and location
- **AND** it SHALL NOT create enabled assignments for other businesses

#### Scenario: Quick-add location creation
- **WHEN** an authorized user creates a location through the shared quick-add modal
- **THEN** the location SHALL receive the same owning-business sale-location assignment as standard creation
- **AND** it SHALL NOT become enabled for unrelated businesses

#### Scenario: Another business wants to use the location
- **WHEN** a location owned by one business is displayed in another business's sale-location configuration
- **THEN** the other business SHALL have to explicitly enable that location before its POS flows can use it

### Requirement: Newly assigned sale locations SHALL be immediately visible to POS
Every creation, enablement, disablement, ownership transfer, reorder, or deletion that changes a business's enabled sale locations SHALL invalidate that business's cached sale-location resolution before the operation is reported successful.

#### Scenario: POS cache existed before location creation
- **WHEN** the owning business's sale-location IDs were cached before a new location was created
- **THEN** the affected cache SHALL be invalidated during location creation
- **AND** the next POS location resolution SHALL include the new location without waiting for cache expiry

#### Scenario: Cross-business location is explicitly enabled
- **WHEN** a business explicitly enables a location owned by another business
- **THEN** that business's cached sale-location resolution SHALL be invalidated
- **AND** the next POS location resolution SHALL include the enabled location

#### Scenario: Sale location is disabled or deleted
- **WHEN** an enabled sale-location assignment is disabled or deleted
- **THEN** the affected business's cached resolution SHALL be invalidated
- **AND** subsequent POS resolution SHALL no longer return that assignment

### Requirement: Sale-location onboarding SHALL be atomic and idempotent
Location creation and its owning-business sale-location assignment SHALL complete as one consistent operation. Repeated lifecycle handling MUST preserve existing enablement choices and MUST NOT create duplicate assignments and failure to establish the required assignment MUST NOT leave a successfully reported but unusable location.

#### Scenario: Assignment already exists
- **WHEN** onboarding encounters an existing assignment for the same business and location
- **THEN** it SHALL preserve its enabled or disabled state without creating a duplicate row

#### Scenario: Assignment persistence fails
- **WHEN** the owning-business sale-location assignment cannot be persisted during location creation
- **THEN** the location creation operation SHALL fail atomically
- **AND** the user SHALL NOT receive a success result for a location unavailable to its owning POS

### Requirement: Location enablement SHALL automatically assign order position
When a sale location is enabled for a business and no explicit position is provided, the system SHALL automatically assign the next available position at the end of that business's enabled location list. Disabled or unassigned locations SHALL NOT consume, determine, or appear in that enabled priority ordering. This prevents database constraint violations and ensures a deterministic order.

#### Scenario: Enabling a location without explicit position
- **WHEN** a business explicitly enables a location owned by another business without specifying a display order position
- **THEN** the system SHALL assign the location a position greater than the maximum position among that business's enabled assignments

#### Scenario: Disabled assignment has a historical position
- **WHEN** a disabled sale-location assignment retains a stored position
- **THEN** the system SHALL exclude that position from enabled-list ordering and from calculating the next enabled position

### Requirement: Owned sale locations SHALL support explicit enablement choices
Users with sale-location edit permission SHALL be able to disable or re-enable an owned location for the active business. Disabling SHALL remove its priority; re-enabling SHALL append it after enabled locations. The change SHALL leave other businesses' assignments and the location's global operational status unchanged.

#### Scenario: Disable and re-enable an owned location
- **WHEN** an authorized user disables an enabled owned location
- **THEN** it SHALL leave the active priority list and appear in the available list with an enable action
- **AND** it SHALL have no active priority position
- **AND** re-enabling it SHALL append it after all currently enabled locations

#### Scenario: Isolated business configuration
- **WHEN** a business disables its owned location for sale-location use
- **THEN** other businesses' assignments SHALL retain their state
- **AND** the location SHALL remain available to inventory and transfers under their existing rules

#### Scenario: Unauthorized modification
- **WHEN** a user without sale-location edit permission attempts to toggle an owned location
- **THEN** the system SHALL deny the operation and preserve its state

### Requirement: Owned assignment defaults SHALL preserve explicit choices
Existing enabled assignments SHALL remain enabled on deployment. New locations and missing owner assignments SHALL default to enabled. Repair and ownership changes SHALL preserve existing assignment enablement choices and priority, including disabled assignments.

#### Scenario: Configuration reload preserves disabled state
- **WHEN** configuration repair runs for an owned location with a disabled assignment
- **THEN** the assignment SHALL remain disabled and visible in the available list after repeated page loads

#### Scenario: Missing assignment is repaired
- **WHEN** an owned location has no owner assignment
- **THEN** repair SHALL create one enabled at the end of the enabled priority list
- **AND** repeated repair SHALL NOT create duplicates

#### Scenario: Ownership transfers to a business with an existing assignment
- **WHEN** location ownership changes to a business with an existing assignment
- **THEN** that assignment's enabled or disabled state and priority SHALL be preserved
- **AND** other businesses' assignment choices SHALL remain unchanged

#### Scenario: Ownership transfers to a business without an assignment
- **WHEN** location ownership changes to a business without an assignment
- **THEN** an enabled assignment SHALL be created for the new owner at the end of its enabled priority list

#### Scenario: Compatible defaults
- **WHEN** the change is deployed and a new location is subsequently created
- **THEN** existing enabled assignments SHALL remain enabled
- **AND** the new location SHALL default to enabled for its owner only

### Requirement: Empty sale-location configuration SHALL be permitted
A business SHALL be allowed to disable its last enabled sale location. POS SHALL use no implicit owner fallback and SHALL block new checkout with a clear message that an enabled sale location must be configured, including for non-stock items.

#### Scenario: Last location is disabled
- **WHEN** a business disables its last enabled sale location
- **THEN** the operation SHALL succeed and subsequent location resolution SHALL be empty
- **AND** a new checkout attempt SHALL fail with a clear configuration message without posting a sale or stock mutation

#### Scenario: Configuration is restored
- **WHEN** a location is enabled after the business had no enabled locations
- **THEN** subsequent POS resolution SHALL include it immediately without waiting for cache expiry

### Requirement: Shared sale-location consumers SHALL honor explicit disablement
POS eligibility, Price Point browsing, and Sales cost-fallback priority SHALL continue to use the shared enabled-location configuration. An explicitly disabled owned location SHALL be excluded wherever that configuration determines eligibility or priority. Existing posted transaction records SHALL remain unchanged.

#### Scenario: Disabled owned location is excluded from shared resolution
- **WHEN** an owned location is disabled after its enabled list was cached
- **THEN** subsequent shared resolution SHALL exclude it immediately
- **AND** POS and Price Point browsing SHALL respect that exclusion
- **AND** Sales cost-fallback ranking SHALL exclude its disabled assignment from enabled-location priority
- **AND** historical transaction records SHALL remain unchanged

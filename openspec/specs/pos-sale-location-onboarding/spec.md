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
Location creation and its owning-business sale-location assignment SHALL complete as one consistent operation. Repeated lifecycle handling MUST NOT create duplicate assignments and failure to establish the required assignment MUST NOT leave a successfully reported but unusable location.

#### Scenario: Assignment already exists
- **WHEN** onboarding encounters an existing assignment for the same business and location
- **THEN** it SHALL ensure that assignment is enabled without creating a duplicate row

#### Scenario: Assignment persistence fails
- **WHEN** the owning-business sale-location assignment cannot be persisted during location creation
- **THEN** the location creation operation SHALL fail atomically
- **AND** the user SHALL NOT receive a success result for a location unavailable to its owning POS

### Requirement: Location enablement SHALL automatically assign order position
When a sale location is enabled for a business and no explicit position is provided, the system SHALL automatically assign the next available position at the end of that business's location list. This prevents database constraint violations and ensures a deterministic order.

#### Scenario: Enabling a location without explicit position
- **WHEN** a business explicitly enables a location owned by another business without specifying a display order position
- **THEN** the system SHALL assign the location a position greater than the maximum existing position for that business

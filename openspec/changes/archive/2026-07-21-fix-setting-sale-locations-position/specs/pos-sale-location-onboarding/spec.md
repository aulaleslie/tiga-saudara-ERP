## ADDED Requirements

### Requirement: Location enablement SHALL automatically assign order position
When a sale location is enabled for a business and no explicit position is provided, the system SHALL automatically assign the next available position at the end of that business's location list. This prevents database constraint violations and ensures a deterministic order.

#### Scenario: Enabling a location without explicit position
- **WHEN** a business explicitly enables a location owned by another business without specifying a display order position
- **THEN** the system SHALL assign the location a position greater than the maximum existing position for that business

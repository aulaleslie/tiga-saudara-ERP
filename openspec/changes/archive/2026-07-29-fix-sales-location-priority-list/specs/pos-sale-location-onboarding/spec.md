## MODIFIED Requirements

### Requirement: Location enablement SHALL automatically assign order position
When a sale location is enabled for a business and no explicit position is provided, the system SHALL automatically assign the next available position at the end of that business's enabled location list. Disabled or unassigned locations SHALL NOT consume, determine, or appear in that enabled priority ordering. This prevents database constraint violations and ensures a deterministic order.

#### Scenario: Enabling a location without explicit position
- **WHEN** a business explicitly enables a location owned by another business without specifying a display order position
- **THEN** the system SHALL assign the location a position greater than the maximum position among that business's enabled assignments

#### Scenario: Disabled assignment has a historical position
- **WHEN** a disabled sale-location assignment retains a stored position
- **THEN** the system SHALL exclude that position from enabled-list ordering and from calculating the next enabled position

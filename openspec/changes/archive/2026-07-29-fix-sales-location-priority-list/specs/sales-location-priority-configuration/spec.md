## ADDED Requirements

### Requirement: Sale-location configuration SHALL separate active priority from disabled availability
The system SHALL display the current business's enabled sale-location assignments as the active POS priority list in ascending configured position. Disabled or unassigned locations owned by other businesses SHALL be displayed separately as available locations and SHALL NOT receive a priority position in that list.

#### Scenario: Unassigned foreign location is available but not prioritized
- **WHEN** a foreign-owned location has no enabled sale-location assignment for the current business
- **THEN** the system SHALL display it in the available disabled-location list
- **AND** it SHALL appear after, and outside, the active POS priority list
- **AND** it SHALL NOT show reorder controls

#### Scenario: Disabled foreign assignment is not prioritized
- **WHEN** a foreign-owned location has a disabled sale-location assignment for the current business
- **THEN** the system SHALL display it as an available disabled location
- **AND** its stored or null position SHALL NOT affect the active POS priority list

### Requirement: Only enabled sale locations SHALL be reorderable
The system SHALL submit and persist priority order only for the current business's enabled sale-location assignments. The reorder operation MUST reject a request whose IDs are not the exact unique set of those enabled assignments and MUST update the accepted positions atomically.

#### Scenario: User reorders active locations
- **WHEN** an authorized user moves an enabled active sale location and saves
- **THEN** the system SHALL persist the active enabled assignments in the submitted contiguous priority order
- **AND** it SHALL invalidate the current business's sale-location resolver cache

#### Scenario: Request includes a disabled or unassigned location
- **WHEN** a reorder request includes a disabled or unassigned location ID
- **THEN** the system SHALL reject the request without changing any priority positions

#### Scenario: Request duplicates a location ID
- **WHEN** a reorder request contains a duplicate location ID
- **THEN** the system SHALL reject the request without changing any priority positions

### Requirement: Enablement SHALL precede priority management for foreign locations
A foreign-owned location SHALL become reorderable only after the current business explicitly enables it. Enablement SHALL place the location after the current maximum enabled priority position, after which the active list SHALL include it.

#### Scenario: User enables an available foreign location
- **WHEN** an authorized user enables a foreign location from the available list
- **THEN** the system SHALL create or reactivate its enabled assignment with the next enabled priority position
- **AND** the next configuration page render SHALL show it in the active priority list

### Requirement: Owned active locations SHALL have enabled assignments
Every location owned by the current business that is represented as active in sales-location configuration SHALL be backed by an enabled sale-location assignment with a valid priority position.

#### Scenario: Historical owned location lacks an assignment
- **WHEN** configuration encounters a location owned by the current business without its required sale-location assignment
- **THEN** the system SHALL restore an enabled assignment with the next valid priority position before allowing it to participate in priority management

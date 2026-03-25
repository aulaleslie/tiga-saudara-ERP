# pos-session-lifecycle Delta Specification

## MODIFIED Requirements

### Requirement: Session can be opened with or without terminal selection

The POS session opening process SHALL allow users to create sessions with an optional terminal assignment. Terminal selection SHALL NOT be required for any user, regardless of role or permissions. Sessions opened without a terminal SHALL have `terminal_id = NULL`.

#### Scenario: Session opened without terminal
- **WHEN** a user with `pos.sessions.open` permission opens a session without selecting a terminal
- **THEN** a new PosSession record SHALL be created with `terminal_id = NULL`
- **AND** the session SHALL enter OPEN status
- **AND** `opening_float_total` may be 0 or null (not required when no terminal)
- **AND** the session is valid and usable in the POS system

#### Scenario: Session opened with terminal
- **WHEN** a user with `pos.sessions.open` permission opens a session and selects a terminal
- **THEN** a new PosSession record SHALL be created with the selected `terminal_id`
- **AND** the session SHALL enter OPEN status
- **AND** the terminal SHALL be allocated to the user (active_marker set appropriately)
- **AND** `opening_float_total` SHALL be provided and validated

#### Scenario: No permission-based terminal requirement
- **WHEN** any authenticated user with `pos.sessions.open` permission attempts to open a session
- **THEN** the system SHALL NOT require terminal selection based on any permission check
- **AND** the system SHALL NOT enforce `pos.sessions.require-terminal` permission for this purpose

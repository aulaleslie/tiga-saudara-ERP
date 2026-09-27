## MODIFIED Requirements

### Requirement: POS Session Opening MUST Enforce Role-Based Terminal Selection
The POS session opening flow SHALL enforce terminal selection requirements using explicit permissions rather than role names. Users with `pos.sessions.require-terminal` MUST select a terminal before a session can be created or re-entered. Users without that permission MUST be allowed to open a session without terminal selection.

#### Scenario: User with terminal-required permission must select terminal
- **WHEN** a user who has `pos.sessions.require-terminal` opens the POS session flow without choosing a terminal
- **THEN** the system MUST reject session creation
- **AND** the response MUST instruct the user to select a terminal before continuing

#### Scenario: User without terminal-required permission can open session without terminal
- **WHEN** a user who lacks `pos.sessions.require-terminal` opens the POS session flow without choosing a terminal
- **THEN** the system MUST allow the session to be created or reused with `terminal_id = null`

#### Scenario: Opening float requirement follows terminal selection
- **WHEN** a session is opened without a terminal because the acting user lacks `pos.sessions.require-terminal`
- **THEN** the system MUST NOT require opening float input
- **AND** the created session MUST persist `opening_float_total = 0`

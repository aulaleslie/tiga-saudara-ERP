## ADDED Requirements

### Requirement: POS Session Opening MUST Enforce Role-Based Terminal Selection
The POS session opening flow SHALL enforce terminal selection requirements based on actor role permissions.

#### Scenario: Floor staff opens session without terminal input
- **WHEN** a Floor Staff user opens POS sell page
- **THEN** the system MUST allow active session opening without requiring terminal selection

#### Scenario: Cashier must select terminal
- **WHEN** a Cashier Staff user opens POS sell page
- **THEN** the system MUST require terminal selection before creating or activating POS session

#### Scenario: Store manager opens session without terminal input
- **WHEN** a Store Manager user opens POS sell page
- **THEN** the system MUST allow active session opening without requiring terminal selection

### Requirement: Active POS Session Ownership MUST Prevent User-Level Clashes
The system SHALL enforce at most one active POS session for the same user within the same setting.

#### Scenario: User attempts second active session in same setting
- **WHEN** a user with existing active POS session in a setting attempts to open another session in the same setting
- **THEN** the system MUST block the new activation and MUST return a conflict result identifying the active session owner and context

#### Scenario: User re-enters the same active session context
- **WHEN** the user returns to POS with matching active session context
- **THEN** the system MUST reuse the existing active session instead of creating duplicate active records

### Requirement: Terminal Assignment MUST Prevent Concurrent Ownership
The system SHALL enforce at most one active POS session per `(setting, terminal)` for terminal-bound sessions.

#### Scenario: Another cashier attempts to use occupied terminal
- **WHEN** a terminal already has an active POS session in the same setting and a different user selects that terminal
- **THEN** the system MUST reject activation with terminal conflict outcome

#### Scenario: Terminal assignment succeeds when free
- **WHEN** cashier selects a terminal that has no active session in the current setting
- **THEN** the system MUST create or activate a terminal-bound session for that user

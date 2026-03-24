# pos-super-admin-setting-bypass Specification

## Purpose
This specification defines the requirement for Super Admin users to bypass business setting assignment checks for critical POS terminal operations.

## Requirements

### Requirement: Super Admin Role Detects and Bypasses Business Setting Assignment
The POS system SHALL recognize users with the `Super Admin` role and allow them to perform terminal-specific actions (close, safe drop, finalize) even if the user is not explicitly assigned to the business setting in the `user_setting` table.

#### Scenario: Super Admin performs action without setting assignment
- **WHEN** a user with the `Super Admin` role attempts a terminal action (e.g., safe drop) for a setting they are NOT assigned to
- **THEN** the system MUST allow the action to proceed
- **AND** the system MUST NOT throw an "Actor user is not assigned to current setting" error

#### Scenario: Non-Super Admin still requires setting assignment
- **WHEN** a user WITHOUT the `Super Admin` role attempts a terminal action for a setting they are NOT assigned to
- **THEN** the system MUST throw an "Actor user is not assigned to current setting" error
- **AND** the action MUST be blocked

## ADDED Requirements

### Requirement: Reporting-date overrides can participate in an atomic combined date adjustment
The system SHALL allow an authorized reporting-date create, replacement, or clear operation to be submitted together with an independently authorized due-date replacement. Authorization SHALL be evaluated separately for every requested field, and the system SHALL commit all effective changes and their audit entries atomically.

#### Scenario: Both authorized changes commit together
- **WHEN** a user authorized for both fields submits a valid reporting-date change and due-date change in one request
- **THEN** the system SHALL commit both document changes and both audit entries in one database transaction

#### Scenario: Failure of either change rolls back both
- **WHEN** validation, authorization, persistence, or audit creation fails for either field in a combined request
- **THEN** the system SHALL preserve both prior document values
- **AND** the system SHALL not persist an audit entry for either requested change

#### Scenario: Reporting-only user changes only reporting date
- **WHEN** a user with the applicable reporting-date permission but without the due-date permission submits only a valid reporting-date operation
- **THEN** the system SHALL process the reporting-date operation under the existing reporting-date rules

#### Scenario: Reporting-only user cannot include due date
- **WHEN** a user with the applicable reporting-date permission but without the due-date permission includes a due-date replacement in the same request
- **THEN** the system SHALL deny the combined request
- **AND** neither date nor audit history SHALL change


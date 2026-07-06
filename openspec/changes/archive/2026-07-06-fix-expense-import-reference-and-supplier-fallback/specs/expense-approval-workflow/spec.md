## MODIFIED Requirements

### Requirement: Expense references are setting scoped
The system SHALL generate expense references using the expense date, current setting document prefix, and a monthly sequence that is unique per setting.

#### Scenario: Reference uses setting document prefix
- **WHEN** a new expense is created in a setting with document prefix `TNC`
- **THEN** the generated reference MUST begin with `TNC-EXP-`

#### Scenario: Reference uses expense date for year and month
- **WHEN** a new expense dated `2026-01-06` is created while the current calendar date is in a different month
- **THEN** the generated reference MUST use `2026-01` as the reference year and month

#### Scenario: Reference sequence is scoped per setting and expense month
- **WHEN** two settings create expenses with dates in the same month
- **THEN** each setting MUST receive its own monthly expense reference sequence

#### Scenario: Backdated expense uses matching date bucket
- **WHEN** an expense is created with a date in a prior month
- **THEN** the next reference number MUST be calculated from existing expenses in the same setting and same expense-date month

#### Scenario: Duplicate reference is rejected
- **WHEN** a persistence attempt would create a duplicate `setting_id` and `reference` pair
- **THEN** the system MUST prevent the duplicate and retry or surface a safe validation failure

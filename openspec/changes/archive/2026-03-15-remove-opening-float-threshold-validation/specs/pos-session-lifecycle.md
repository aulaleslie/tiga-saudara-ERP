## ADDED Requirements

### Requirement: Opening float validation without threshold gating

The system SHALL allow a cashier to open a new POS session with any positive opening float, without validating against the terminal policy cash_threshold. The cash_threshold policy remains available for monitoring and visibility purposes but does not gate session creation.

#### Scenario: Cashier opens session with opening float below policy threshold

- **WHEN** a cashier initiates session open with a positive opening float that is less than the terminal policy's cash_threshold
- **THEN** the session SHALL be created successfully with OPEN status
- **AND** the opening float SHALL be recorded in the session
- **AND** no error message about threshold violation SHALL be returned

#### Scenario: Cashier opens session with any positive opening float

- **WHEN** a cashier initiates session open via POST `/pos/sessions` with opening_float_total > 0
- **THEN** the session SHALL be created regardless of the terminal policy cash_threshold value
- **AND** the terminal policy cash_threshold remains available for monitoring services
- **AND** the session is immediately usable for transactions

#### Scenario: Threshold policy remains in configuration and monitoring

- **WHEN** a supervisor or monitoring service queries session summary or monitor data
- **THEN** the cash_threshold from terminal policy SHALL be available for display and threshold-aware calculations
- **AND** the threshold value indicates the policy limit for stakeholder visibility
- **AND** sessions with opening floats below threshold are flagged in monitoring (not blocked at open time)

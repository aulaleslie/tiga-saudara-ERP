## MODIFIED Requirements

### Requirement: purchase-receiving-form-resilience
The purchase receiving form MUST prevent empty submissions, visibly show localized validation feedback, preserve submitted form values after rejected submissions, and handle submission states gracefully to avoid intermittent errors.

#### Scenario: preventing empty save
- **WHEN** a user hits the save button on the purchase receiving form without inputting any quantities or serials
- **THEN** the system prevents submission and provides immediate feedback to the user

#### Scenario: missing required location is visible after validation
- **WHEN** a user submits at least one positive received quantity without selecting a location
- **THEN** the system SHALL reject the submission and create no receiving note
- **AND** the form SHALL visibly display an Indonesian location validation message and preserve submitted values

## ADDED Requirements

### Requirement: Correction form follows application UI conventions
The system SHALL present the received-purchase correction form using the application's standard layout, form, and button styling, and SHALL communicate validation results, preview errors, and submission outcomes through the application's standard non-blocking feedback patterns rather than blocking browser dialogs.

#### Scenario: Correction page renders consistently
- **WHEN** a privileged user opens the correction form for a received purchase
- **THEN** the page's cards, inputs, selects, and buttons SHALL render with the application's standard styling as loaded by the application's CSS framework

#### Scenario: Submission feedback is non-blocking
- **WHEN** a correction submission succeeds or fails
- **THEN** the outcome SHALL be shown through the application's standard flash or inline alert pattern
- **AND** no blocking browser dialog SHALL be used

### Requirement: Correction preview requests are debounced
The system SHALL debounce payment-preview recalculation triggered by continuous typing in correction inputs so that a burst of keystrokes results in a bounded number of preview requests, while explicit selection changes refresh the preview promptly.

#### Scenario: Typing in a monetary field
- **WHEN** a privileged user types a multi-digit value into a correction input
- **THEN** the system SHALL not issue a preview request per keystroke
- **AND** a preview request SHALL be issued shortly after typing pauses

#### Scenario: Changing the selected payment
- **WHEN** a privileged user changes the payment selected for correction
- **THEN** the preview SHALL refresh promptly for the newly selected payment

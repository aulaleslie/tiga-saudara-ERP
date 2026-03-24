## ADDED Requirements

### Requirement: Interactive supervisor override during POS settlement
When a terminal settlement (finalization) is blocked because the variance exceeds the allowed threshold, the system SHALL provide an interactive mechanism within the finalization modal to perform a supervisor override.

#### Scenario: Supervisor override prompt appears on block
- **WHEN** a supervisor (without override permission) attempts to finalize a session
- **AND** the backend returns a `requires_variance_approval` error
- **THEN** the finalization modal SHALL display a supervisor authentication form
- **AND** the form SHALL require a supervisor's identifier (email) and password (or PIN)
- **AND** a "Confirm Override" button SHALL be enabled once credentials are provided

#### Scenario: Successful override transitions to FINALIZED
- **WHEN** a valid supervisor's credentials (with `pos.sessions.approve-variance` permission) are entered into the override form
- **AND** the "Confirm Override" button is clicked
- **THEN** the system SHALL attempt to finalize the session again using these credentials for authorization
- **AND** if successful, the session SHALL transition to FINALIZED status
- **AND** the modal SHALL close with a success message

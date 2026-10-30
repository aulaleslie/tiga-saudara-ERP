## MODIFIED Requirements

### Requirement: Opening float is conditional on terminal selection

A strictly positive opening float SHALL be required only when a terminal is selected. The system SHALL derive it from enabled global denomination quantities by default, or accept a nominal total when the user explicitly selects nominal mode and has `pos.sessions.open.total-only`. Without a terminal, opening cash SHALL remain optional and normalize to zero.

#### Scenario: Float required when terminal selected
- **WHEN** a user selects a terminal in the session opening form
- **THEN** denomination quantity inputs and a calculated opening total SHALL be displayed by default
- **AND** submission without positive counted cash or an authorized positive nominal total SHALL fail validation

#### Scenario: Float optional when no terminal
- **WHEN** a user does not select a terminal in the session opening form
- **THEN** opening cash inputs SHALL not be required
- **AND** form submission without a float value SHALL succeed with opening cash and initial expected cash of zero

#### Scenario: Authorized nominal entry
- **WHEN** a user with `pos.sessions.open.total-only` explicitly selects nominal mode for a terminal session
- **THEN** a strictly positive manually entered opening total SHALL satisfy the opening cash requirement without counts

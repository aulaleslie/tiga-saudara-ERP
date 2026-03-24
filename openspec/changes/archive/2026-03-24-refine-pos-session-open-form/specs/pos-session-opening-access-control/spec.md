## MODIFIED Requirements

### Requirement: Total Saldo Awal is mandatory only when Terminal is selected
The system SHALL require the Total Saldo Awal field to be filled only when a Terminal has been selected. When no Terminal is selected, the Total Saldo Awal field is optional.

#### Scenario: User selects terminal - Saldo becomes required
- **WHEN** a user selects a Terminal from the Terminal dropdown
- **THEN** the Total Saldo Awal field changes from optional to required (indicated by "*" in the label)
- **AND** the field's `required` attribute is set
- **AND** the field becomes visible on the form

#### Scenario: User clears terminal selection - Saldo becomes optional
- **WHEN** a user clears/deselects the Terminal field using the clear button or by emptying the selection
- **THEN** the Total Saldo Awal field changes from required to optional
- **AND** the field's `required` attribute is removed
- **AND** the field becomes hidden from view

#### Scenario: User submits form with terminal but no Saldo
- **WHEN** a user selects a Terminal but leaves Total Saldo Awal empty and submits the form
- **THEN** the form submission is rejected with a validation error for Total Saldo Awal

#### Scenario: User submits form without terminal and without Saldo
- **WHEN** a user leaves both Terminal and Total Saldo Awal empty and submits the form
- **THEN** the form submission is accepted (form validation passes)

### Requirement: Dynamic field requirement indicators
The system SHALL provide real-time visual indicators for Total Saldo Awal field requirements that update as Terminal selection changes.

#### Scenario: Form loads with no terminal selected
- **WHEN** the "Buka Sesi POS" form initially loads
- **THEN** the Total Saldo Awal field is hidden
- **AND** the field label is not displayed

#### Scenario: User selects terminal - indicator updates
- **WHEN** a user selects a Terminal from the dropdown
- **THEN** the Total Saldo Awal field becomes visible
- **AND** the label immediately updates to show "*" (required indicator)
- **AND** the help text displays "Wajib diisi saat membuka sesi dengan terminal"

#### Scenario: User clears terminal selection - indicator reverts
- **WHEN** a user clears the Terminal selection using the clear button
- **THEN** the Total Saldo Awal field immediately becomes hidden
- **AND** the field label is hidden
- **AND** the help text is hidden

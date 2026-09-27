## ADDED Requirements

### Requirement: Terminal and Saldo fields visibility based on pos.sell permission
The system SHALL hide the Terminal selection and Total Saldo Awal input fields from users who lack the `pos.sell` permission. Users with `pos.sell` permission SHALL see both fields on the "Buka Sesi POS" form.

#### Scenario: User with pos.sell permission views the form
- **WHEN** a user with `pos.sessions.open` AND `pos.sell` permissions navigates to `/pos/sessions/open`
- **THEN** the form displays Terminal selection field and Total Saldo Awal input field

#### Scenario: User without pos.sell permission views the form
- **WHEN** a user with `pos.sessions.open` but WITHOUT `pos.sell` permission navigates to `/pos/sessions/open`
- **THEN** the Terminal selection field and Total Saldo Awal input field are hidden from view
- **AND** the Notes field remains visible
- **AND** the Submit button is still present and enabled

### Requirement: Terminal selection is optional for all users
The system SHALL make the Terminal selection field optional (nullable). Users may open a POS session without selecting a terminal.

#### Scenario: User submits form without terminal selection
- **WHEN** a user leaves the Terminal field empty and submits the "Buka Sesi POS" form
- **THEN** the form submission is accepted (assuming other required fields are valid)

#### Scenario: User submits form with terminal selection
- **WHEN** a user selects a Terminal from the dropdown and submits the form
- **THEN** the form submission is accepted (assuming other required fields are valid)

### Requirement: Total Saldo Awal is mandatory only when Terminal is selected
The system SHALL require the Total Saldo Awal field to be filled only when a Terminal has been selected. When no Terminal is selected, the Total Saldo Awal field is optional.

#### Scenario: User selects terminal - Saldo becomes required
- **WHEN** a user selects a Terminal from the Terminal dropdown
- **THEN** the Total Saldo Awal field changes from optional to required (indicated by "*" in the label)
- **AND** the field's `required` attribute is set

#### Scenario: User clears terminal selection - Saldo becomes optional
- **WHEN** a user clears/deselects the Terminal field
- **THEN** the Total Saldo Awal field changes from required to optional (indicated by "(Opsional)" in the label)
- **AND** the field's `required` attribute is removed

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
- **THEN** the Total Saldo Awal label shows "(Opsional)" indicator
- **AND** the field is not marked as required

#### Scenario: User selects terminal - indicator updates
- **WHEN** a user selects a Terminal from the dropdown
- **THEN** the Total Saldo Awal label immediately updates to show "*" (required indicator)
- **AND** the help text displays "Wajib diisi jika Terminal dipilih"

#### Scenario: User clears terminal selection - indicator reverts
- **WHEN** a user clears the Terminal selection
- **THEN** the Total Saldo Awal label immediately updates back to "(Opsional)"
- **AND** the help text remains visible

### Requirement: No change to authorization gate
The system SHALL NOT modify the authorization check for the session opening page. Users must still have `pos.sessions.open` permission to access the form. The `pos.sell` permission is checked for field visibility and validation, not as an authorization gate.

#### Scenario: User without pos.sessions.open permission
- **WHEN** a user without `pos.sessions.open` permission attempts to navigate to `/pos/sessions/open`
- **THEN** the request is denied with a 403 Forbidden error (unchanged behavior)

#### Scenario: User with pos.sessions.open only
- **WHEN** a user with `pos.sessions.open` but no `pos.sell` permission navigates to `/pos/sessions/open`
- **THEN** the page loads successfully (no 403 error)
- **BUT** form fields are hidden as per access control requirements

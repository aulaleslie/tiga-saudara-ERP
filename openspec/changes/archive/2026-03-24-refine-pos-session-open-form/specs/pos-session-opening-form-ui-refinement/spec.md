## ADDED Requirements

### Requirement: Terminal dropdown clear button
The system SHALL provide a clear/reset button integrated into the Terminal dropdown toggle button to allow users to quickly deselect a terminal without reloading the form.

#### Scenario: Clear button visible when terminal is selected
- **WHEN** a user has selected a terminal in the Terminal dropdown
- **THEN** a clear button icon (×) appears inside the dropdown toggle button

#### Scenario: Clear button hidden when no terminal is selected
- **WHEN** the Terminal dropdown has no selection
- **THEN** the clear button icon is not visible

#### Scenario: User clicks clear button
- **WHEN** a user with a terminal selected clicks the clear button icon
- **THEN** the terminal selection is cleared
- **AND** the Terminal dropdown displays the placeholder text "Pilih terminal..."
- **AND** the hidden field value is empty
- **AND** the clear button icon disappears

#### Scenario: Clear button does not toggle dropdown
- **WHEN** a user clicks the clear button icon while the dropdown is open
- **THEN** the dropdown does NOT close
- **AND** the terminal selection is cleared

### Requirement: Total Saldo Awal field visibility based on terminal selection
The system SHALL hide the Total Saldo Awal field and its label when no terminal is selected, and show it when a terminal is selected.

#### Scenario: Form loads with no terminal selected
- **WHEN** the "Buka Sesi POS" form loads
- **THEN** the Total Saldo Awal field is hidden from view
- **AND** the field's label is not displayed
- **AND** the help text "Wajib diisi saat membuka sesi dengan terminal" is not visible

#### Scenario: User selects a terminal
- **WHEN** a user selects a terminal from the Terminal dropdown
- **THEN** the Total Saldo Awal field becomes visible
- **AND** the field's label is displayed
- **AND** the field is marked as required (indicated by asterisk)

#### Scenario: User clears terminal selection
- **WHEN** a user clears the terminal selection using the clear button
- **THEN** the Total Saldo Awal field becomes hidden
- **AND** the field's label is hidden
- **AND** the help text is hidden

### Requirement: Total Saldo Awal field conditional requirement
The system SHALL make the Total Saldo Awal field mandatory only when a terminal is selected, and optional when no terminal is selected.

#### Scenario: Saldo field required when terminal selected
- **WHEN** a user selects a terminal from the Terminal dropdown
- **THEN** the Total Saldo Awal field's `required` HTML attribute is set
- **AND** the field label shows a required indicator (*)

#### Scenario: Saldo field optional when no terminal
- **WHEN** the Terminal dropdown has no selection
- **THEN** the Total Saldo Awal field's `required` HTML attribute is NOT set
- **AND** the field is optional for form submission

#### Scenario: Saldo field becomes optional when terminal cleared
- **WHEN** a user clears the terminal selection
- **THEN** the Total Saldo Awal field's `required` attribute is removed
- **AND** any existing value in the field is preserved (field is hidden but data retained)

### Requirement: Currency prefix removal from Saldo field
The system SHALL remove the hardcoded "Rp" currency prefix from the Total Saldo Awal input field while preserving number formatting functionality.

#### Scenario: Saldo input has no currency prefix
- **WHEN** a user views the Total Saldo Awal input field
- **THEN** the "Rp" currency prefix is not displayed
- **AND** the field displays a clean number input without decorative currency text

#### Scenario: Number formatting still works without prefix
- **WHEN** a user types a number into the Total Saldo Awal field (e.g., 1000000)
- **THEN** the display input formats the number with locale-aware thousands separator (e.g., "1.000.000")
- **AND** the hidden field stores the unformatted numeric value (1000000)

#### Scenario: Form submission includes correct Saldo value
- **WHEN** a user submits the form after entering a Saldo value
- **THEN** the submitted value is the unformatted numeric amount (no currency prefix, no separators)

## ADDED Requirements

### Requirement: Terminal cash threshold has clear description and default value
The system SHALL display `cash_threshold` field with help text explaining its purpose, a default value of 5,000,000, and guidance on expected format.

#### Scenario: Form displays threshold field with description
- **WHEN** administrator opens terminal configuration form at `/pos/terminals/create` or `/pos/terminals/{id}/edit`
- **THEN** the form shows:
  - Field label: "Batas Kas"
  - Help text explaining: "Ketika kas yang diharapkan melebihi batas ini, peringatan akan ditampilkan di monitor. Supervisor dapat tetap melakukan pengambilan di bawah batas ini."
  - Example format: "Rp 1.000.000,00"
  - Input type: currency number field

#### Scenario: Default value applied to new terminals
- **WHEN** administrator creates a new terminal without specifying cash_threshold
- **THEN** system defaults cash_threshold to 5,000,000

#### Scenario: Existing terminals retain configured value
- **WHEN** administrator edits an existing terminal that already has cash_threshold configured
- **THEN** the form pre-fills with existing value and can be modified

#### Scenario: Threshold affects monitor dashboard display
- **WHEN** expected_cash_total exceeds terminal's cash_threshold
- **THEN** the session row in monitor dashboard highlights in red with "Terlampaui" badge

### Requirement: Unused close variance approval threshold is removed
The system SHALL remove the `close_variance_approval_threshold` field from terminal configuration as it is unused in the codebase.

#### Scenario: Field removed from create form
- **WHEN** administrator navigates to terminal creation form
- **THEN** "Ambang persetujuan selisih penutupan" field is not present

#### Scenario: Field removed from edit form
- **WHEN** administrator navigates to edit an existing terminal
- **THEN** "Ambang persetujuan selisih penutupan" field is not present

#### Scenario: Database migration handles removal
- **WHEN** migration is run
- **THEN** close_variance_approval_threshold column is dropped from pos_terminal_policies table safely (backing up data if needed)

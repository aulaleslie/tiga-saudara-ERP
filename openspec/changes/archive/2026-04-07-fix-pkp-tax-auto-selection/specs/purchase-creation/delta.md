## MODIFIED Requirements

### Requirement: Purchase creation resolves line tax by PKP policy

#### Scenario: PKP purchase falls back to any available tax when default is missing
- **WHEN** a user creates a purchase and the active setting has `is_pkp = true`
- **AND** the selected product does not have a configured purchase tax for the active setting
- **AND** no tax is explicitly marked as "default" in the database
- **AND** at least one tax exists in the system
- **THEN** the purchase cart line SHALL auto-select the first available tax (alphabetical by name)
- **AND** purchase tax calculations SHALL use that fallback tax immediately
- **AND** the UI SHALL display this fallback tax as selected

## ADDED Requirements

### Requirement: PKP tax availability validation

#### Scenario: PKP purchase blocks submission when zero taxes exist
- **WHEN** a user submits a purchase and the active setting has `is_pkp = true`
- **AND** no taxes are configured in the system
- **THEN** the submission SHALL fail with a validation error: "Tidak ada data pajak tersedia. Bisnis PKP wajib mengatur setidaknya satu data pajak."

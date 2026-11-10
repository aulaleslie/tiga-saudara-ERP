# Spec Delta

## MODIFIED Requirements

### Requirement: Purchase creation resolves line tax by PKP policy

The purchase creation flow SHALL resolve purchase-line tax according to the active setting's PKP state.

#### Scenario: PKP purchase uses product purchase tax when configured
- **WHEN** a user creates a purchase and the active setting has `is_pkp = true`
- **AND** no default tax is configured in the system (`is_default = true`)
- **AND** the selected product has a configured purchase tax for the active setting
- **THEN** the purchase cart line SHALL use that product purchase tax
- **AND** purchase tax calculations SHALL use that persisted line tax

#### Scenario: PKP purchase falls back to default tax when product tax is missing
- **WHEN** a user creates a purchase and the active setting has `is_pkp = true`
- **AND** a default tax exists (`is_default = true`)
- **THEN** the purchase cart line SHALL use the default tax
- **AND** purchase tax calculations SHALL use that default tax immediately
- **AND** the UI SHALL display this default tax as selected

#### Scenario: PKP purchase falls back to any available tax when default is missing
- **WHEN** a user creates a purchase and the active setting has `is_pkp = true`
- **AND** the selected product does not have a configured purchase tax for the active setting
- **AND** no tax is explicitly marked as "default" in the database
- **THEN** the purchase cart line SHALL NOT fall back to any available tax and SHALL remain null
- **AND** purchase tax calculations SHALL use zero tax amount until manually selected
- **AND** the UI SHALL display no tax selected

#### Scenario: PKP purchase blocks unresolved tax lines
- **WHEN** a user submits a purchase and the active setting has `is_pkp = true`
- **AND** one or more purchase lines still have no resolved tax
- **THEN** the submission SHALL fail with a validation error indicating that purchase tax is required

#### Scenario: PKP cart mounts with existing taxless row and default tax exists
- **WHEN** a PKP cart opens with an existing row without tax and a default tax exists
- **THEN** the row's tax SHALL be set to the default tax
- **AND** a row that already has a tax SHALL keep it

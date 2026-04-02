## MODIFIED Requirements

### Requirement: Manual Tax Assignment in Purchase/Sale Carts
The system MUST NOT automatically assign a default or latest tax to cart items in PKP mode when no explicit tax is provided. Tax assignment SHALL be an explicit user action, and when a user explicitly selects a tax for a cart line, the system MUST persist that selected tax to the cart row immediately so subsequent totals and validations use the chosen value in the same interaction.

#### Scenario: Adding a taxless product to a PKP Purchase Cart
- **WHEN** a product without an assigned tax is added to a Purchase Cart
- **AND** the system setting `is_pkp` is TRUE
- **THEN** the cart line `tax_id` SHALL remain NULL
- **AND** the line SHALL be displayed as a required-tax placeholder until a tax is selected

#### Scenario: Adding a taxless product to a PKP Sale Cart
- **WHEN** a product without an assigned tax is added to a Sale Cart
- **AND** the system setting `is_pkp` is TRUE
- **THEN** the cart line `tax_id` SHALL remain NULL
- **AND** the line SHALL be displayed as a required-tax placeholder until a tax is selected

#### Scenario: Selecting a tax for the first time on a PKP Purchase Cart line
- **WHEN** a PKP Purchase Cart line currently has `tax_id = NULL`
- **AND** the user selects a tax from the cart tax selector
- **THEN** the selected tax SHALL be persisted to the cart row in the same interaction
- **AND** cart subtotal and tax calculations SHALL use the selected tax immediately
- **AND** subsequent PKP validation SHALL treat the line as tax-assigned

#### Scenario: Selecting a tax for the first time on a PKP Sale Cart line
- **WHEN** a PKP Sale Cart line currently has `tax_id = NULL`
- **AND** the user selects a tax from the cart tax selector
- **THEN** the selected tax SHALL be persisted to the cart row in the same interaction
- **AND** cart subtotal and tax calculations SHALL use the selected tax immediately
- **AND** subsequent PKP validation SHALL treat the line as tax-assigned

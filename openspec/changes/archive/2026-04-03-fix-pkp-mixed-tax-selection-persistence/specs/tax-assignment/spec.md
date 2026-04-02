## MODIFIED Requirements

### Requirement: Manual Tax Assignment in Purchase/Sale Carts
The system MUST NOT automatically assign a default or latest tax to cart items in PKP mode when no explicit tax is provided. Tax assignment SHALL be an explicit user action, and when a user explicitly selects a tax for a cart line, the system MUST persist that selected tax to the cart row immediately so subsequent totals and validations use the chosen value in the same interaction. When multiple PKP cart lines are present, each line's persisted tax selection MUST remain independent of the other lines, including when one line selects the tax marked as default and another line selects a non-default tax.

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

#### Scenario: Mixed PKP purchase cart lines keep independent persisted taxes
- **WHEN** a PKP Purchase Cart contains multiple lines with `tax_id = NULL`
- **AND** the user selects the tax marked `is_default` for one line
- **AND** the user selects a different non-default tax for another line
- **THEN** each cart line SHALL persist the exact tax selected for that line
- **AND** no line SHALL inherit or overwrite another line's tax because of selector ordering or recalculation
- **AND** PKP validation SHALL accept the cart if every line has an explicit persisted tax

#### Scenario: Mixed PKP sale cart lines keep independent persisted taxes
- **WHEN** a PKP Sale Cart contains multiple lines with `tax_id = NULL`
- **AND** the user selects the tax marked `is_default` for one line
- **AND** the user selects a different non-default tax for another line
- **THEN** each cart line SHALL persist the exact tax selected for that line
- **AND** no line SHALL inherit or overwrite another line's tax because of selector ordering or recalculation
- **AND** PKP validation SHALL accept the cart if every line has an explicit persisted tax

#### Scenario: Default-prioritized option is not treated as selected without persistence
- **WHEN** a PKP cart line renders a tax selector whose first option is the tax marked `is_default`
- **AND** the cart line still has `tax_id = NULL`
- **THEN** the UI SHALL continue to represent the line as requiring an explicit tax selection
- **AND** submit-time validation SHALL continue to treat the line as unassigned until a tax is persisted to the cart row

#### Scenario: Toggling tax inclusion preserves per-line mixed selections
- **WHEN** a PKP cart has multiple lines with different explicitly persisted taxes
- **AND** the user toggles tax inclusion or another recalculation action runs
- **THEN** recalculation SHALL preserve each line's persisted `tax_id`
- **AND** the recalculated subtotals SHALL use the persisted tax for each corresponding line

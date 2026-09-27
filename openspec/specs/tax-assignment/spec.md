# tax-assignment Specification

## Purpose
This specification defines the requirements for manual tax assignment across purchase, sale, and product management workflows, ensuring tax application is an explicit user action rather than a system default.
## Requirements
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

### Requirement: Explicit Tax Assignment in Product Imports
The product import process MUST NOT assign default taxes to products if the import source (e.g., CSV) does not specify them.

#### Scenario: Importing products without tax columns
- **WHEN** a CSV file is uploaded for product import
- **AND** the file does not contain `purchase_tax` or `sale_tax` data
- **THEN** the imported product records SHALL have NULL tax IDs
- **AND** the system MUST NOT fallback to hardcoded defaults (e.g., PPN 11%).

### Requirement: Ignore Incoming Product Tax When Non-PKP
The system MUST actively ignore any pre-assigned or incoming `tax_id` from the Cartesian details when processing sale or purchase row inserts or updates if the `is_pkp` setting is FALSE. In this non-PKP context, the resulting saved row details MUST have `tax_id = null` and a calculated `product_tax_amount = 0`, overriding whatever the cart or product model initially supplied. For non-PKP sale persistence, the system MUST also recompute persisted line subtotals and header totals using tax-excluded values so hidden or restored tax-bearing cart state cannot survive as gross saved amounts.

#### Scenario: Storing a purchase with a tax-assigned product when Non-PKP
- **WHEN** a user Submits a Purchase Cart (Store or Update) and `is_pkp` is false
- **AND** a Cartesian row has a `tax_id` populated (e.g., from the Product default)
- **THEN** the backend process SHALL intercept this and set `tax_id = null` 
- **AND** the backend process SHALL persist the row with `product_tax_amount = 0`.

#### Scenario: Storing a sale with a tax-assigned product when Non-PKP
- **WHEN** a user Submits a Sale Cart (Store or Update) and `is_pkp` is false
- **AND** a Cartesian row has a `tax_id` populated (e.g., from the Product default)
- **THEN** the backend process SHALL intercept this and set `tax_id = null`
- **AND** the backend process SHALL persist the row with `product_tax_amount = 0`.

#### Scenario: Storing a non-PKP sale with hidden tax-bearing cart economics
- **WHEN** a user Submits a Sale Cart (Store or Update) and `is_pkp` is false
- **AND** a sale cart row contains hidden or restored tax-bearing values such as `product_tax_amount > 0` or a tax-inflated `sub_total`
- **THEN** the backend process SHALL persist the row with `tax_id = null`
- **AND** the backend process SHALL persist the row with `product_tax_amount = 0`
- **AND** the backend process SHALL persist the row `sub_total` using the tax-excluded amount
- **AND** the backend process SHALL recompute sale `tax_amount = 0`
- **AND** the backend process SHALL recompute sale `total_amount` and `due_amount` from normalized non-tax line economics.

#### Scenario: Re-saving a restored non-PKP sale edit does not preserve hidden tax
- **WHEN** a user opens a Sale Edit flow while `is_pkp` is false
- **AND** restored cart state contains line values derived from previously tax-bearing sale details
- **AND** the user updates and saves the sale
- **THEN** the persisted sale details SHALL not retain hidden tax-bearing economic amounts
- **AND** the persisted sale header SHALL reflect normalized tax-excluded totals for non-PKP mode.

### Requirement: Non-PKP Purchase Persistence Uses Tax-Excluded Amounts
When the current purchase setting has `is_pkp = false`, the purchase persistence pipeline MUST treat any incoming purchase tax state as invalid and MUST persist the purchase using tax-excluded amounts. This rule SHALL apply to purchase creation and purchase updates across Livewire and controller-backed flows, even when cart state or restored purchase details still contain `tax_id`, `product_tax_amount`, or tax-inflated subtotals from prior defaults or saved data.

#### Scenario: Creating a non-PKP purchase from Livewire cart state with hidden product tax
- **WHEN** a user submits a purchase through the Livewire create flow
- **AND** the current setting has `is_pkp = false`
- **AND** one or more cart lines still contain a `tax_id` or non-zero `product_tax_amount`
- **THEN** the persisted purchase details SHALL store `tax_id = null`
- **AND** the persisted purchase details SHALL store `product_tax_amount = 0`
- **AND** each persisted purchase detail `sub_total` SHALL equal the tax-excluded amount for that line
- **AND** the persisted purchase header `tax_amount` SHALL equal `0`

#### Scenario: Updating a non-PKP purchase from restored taxed detail state
- **WHEN** a user opens an existing purchase in the Livewire edit flow
- **AND** restored cart state contains previously saved `tax_id` or tax-bearing subtotals
- **AND** the current setting has `is_pkp = false`
- **THEN** submitting the update SHALL persist purchase details with `tax_id = null`
- **AND** submitting the update SHALL persist purchase details with `product_tax_amount = 0`
- **AND** the saved purchase totals SHALL be recomputed from normalized tax-excluded line subtotals plus discount and shipping inputs

#### Scenario: Creating a non-PKP purchase from controller-backed payload with tax-bearing cart rows
- **WHEN** a request submits purchase cart rows through a controller-backed create path
- **AND** the current setting has `is_pkp = false`
- **AND** one or more request rows include `tax_id` or tax-inflated totals
- **THEN** the persistence layer SHALL ignore the incoming tax identifiers
- **AND** the saved purchase details SHALL use tax-excluded subtotals
- **AND** the saved purchase header totals SHALL be recomputed from normalized detail values

#### Scenario: Non-PKP purchase totals do not preserve hidden gross tax
- **WHEN** a non-PKP purchase line arrives with a gross total that includes tax
- **AND** the tax component can be derived from the tax-bearing line state
- **THEN** the saved purchase line SHALL use the tax-excluded subtotal rather than the gross tax-inflated amount
- **AND** the saved purchase header total SHALL exclude the removed tax component

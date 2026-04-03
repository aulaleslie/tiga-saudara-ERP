## ADDED Requirements

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

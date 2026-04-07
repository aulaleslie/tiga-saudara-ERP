# purchase-creation Specification

## Purpose

This specification defines the requirements for the purchase creation workflow, including the initialization and persistence of tax states, automatic tax resolution based on PKP policies, and state synchronization during product quick-add operations.

## Requirements
### Requirement: CreateForm initializes is_tax_included based on PKP status

The CreateForm component SHALL initialize the `is_tax_included` property to `true` for new PKP purchases and `false` for non-PKP purchases during the mount() lifecycle method.

#### Scenario: PKP purchase form mounts
- **WHEN** CreateForm mounts for a new purchase with `isPkp = true` and no duplicate ID
- **THEN** `is_tax_included` SHALL be initialized to `true`

#### Scenario: Non-PKP purchase form mounts
- **WHEN** CreateForm mounts for a new purchase with `isPkp = false` and no duplicate ID
- **THEN** `is_tax_included` SHALL be initialized to `false`

#### Scenario: Duplicate purchase form mounts
- **WHEN** CreateForm mounts with a `duplicateId` parameter
- **THEN** `is_tax_included` SHALL be populated from the duplicated purchase's value (not defaulted)

### Requirement: ProductCart dispatches initial tax-included state

The ProductCart component SHALL dispatch the `taxIncludedUpdated` event during mount() to communicate its `is_tax_included` state to the parent CreateForm, regardless of whether the component is mounted for a new or existing purchase.

#### Scenario: ProductCart dispatch on new purchase
- **WHEN** ProductCart mounts with `$data = null` (new purchase, not duplicating)
- **THEN** it SHALL dispatch `taxIncludedUpdated` event with `is_tax_included = true`

#### Scenario: ProductCart dispatch on duplicate purchase
- **WHEN** ProductCart mounts with `$data` containing an existing purchase
- **THEN** it SHALL dispatch `taxIncludedUpdated` event with the purchase's `is_tax_included` value

#### Scenario: CreateForm receives initial state
- **WHEN** ProductCart dispatches `taxIncludedUpdated` on mount
- **THEN** CreateForm's `handleTaxIncludedUpdated()` listener SHALL update `is_tax_included` to the dispatched value

### Requirement: Purchase submission persists final tax-included state

When a purchase is submitted, the system SHALL save the current value of `is_tax_included` from the CreateForm component, reflecting any changes made by the user during the form lifecycle.

#### Scenario: Submit new PKP purchase
- **WHEN** user submits a new PKP purchase form via CreateForm.submit()
- **THEN** Purchase::create() SHALL be called with `'is_tax_included' => $this->is_tax_included`

#### Scenario: Submit new non-PKP purchase
- **WHEN** user submits a new non-PKP purchase form via CreateForm.submit()
- **THEN** Purchase::create() SHALL be called with `'is_tax_included' => $this->is_tax_included`

### Requirement: Purchase creation resolves line tax by PKP policy

The purchase creation flow SHALL resolve purchase-line tax according to the active setting's PKP state.

#### Scenario: PKP purchase uses product purchase tax when configured
- **WHEN** a user creates a purchase and the active setting has `is_pkp = true`
- **AND** the selected product has a configured purchase tax for the active setting
- **THEN** the purchase cart line SHALL use that product purchase tax
- **AND** purchase tax calculations SHALL use that persisted line tax

#### Scenario: PKP purchase falls back to default tax when product tax is missing
- **WHEN** a user creates a purchase and the active setting has `is_pkp = true`
- **AND** the selected product does not have a configured purchase tax for the active setting
- **AND** a default tax exists
- **THEN** the purchase cart line SHALL use the default tax
- **AND** purchase tax calculations SHALL use that default tax immediately
- **AND** the UI SHALL display this default tax as selected

#### Scenario: PKP purchase falls back to any available tax when default is missing
- **WHEN** a user creates a purchase and the active setting has `is_pkp = true`
- **AND** the selected product does not have a configured purchase tax for the active setting
- **AND** no tax is explicitly marked as "default" in the database
- **AND** at least one tax exists in the system
- **THEN** the purchase cart line SHALL auto-select the first available tax (alphabetical by name)
- **AND** purchase tax calculations SHALL use that fallback tax immediately
- **AND** the UI SHALL display this fallback tax as selected

#### Scenario: PKP purchase blocks unresolved tax lines
- **WHEN** a user submits a purchase and the active setting has `is_pkp = true`
- **AND** one or more purchase lines still have no resolved tax
- **THEN** the submission SHALL fail with a validation error indicating that purchase tax is required

### Requirement: PKP tax availability validation

#### Scenario: PKP purchase blocks submission when zero taxes exist
- **WHEN** a user submits a purchase and the active setting has `is_pkp = true`
- **AND** no taxes are configured in the system
- **THEN** the submission SHALL fail with a validation error: "Tidak ada data pajak tersedia. Bisnis PKP wajib mengatur setidaknya satu data pajak."

### Requirement: Product quick-add tax auto-selection persists through the purchase flow

When a user creates a new tax from the add-product modal inside purchase creation, any tax that becomes selected in the modal UI SHALL also be synchronized into the parent product modal state used for product persistence and emitted purchase payloads.

#### Scenario: Existing purchase tax selected in quick-add product modal persists
- **WHEN** a user opens the add-product modal from purchase creation
- **AND** selects an existing purchase tax from the purchase tax dropdown
- **THEN** the product quick-add state SHALL store that exact `purchase_tax_id`
- **AND** saving the new product SHALL persist that `purchase_tax_id`
- **AND** the emitted product payload into the purchase flow SHALL contain that same `purchase_tax_id`

#### Scenario: Newly created purchase tax auto-selected in quick-add modal persists
- **WHEN** a user opens the add-product modal from purchase creation
- **AND** creates a new tax from the nested purchase tax quick-add modal
- **AND** the purchase tax dropdown auto-selects the new tax
- **THEN** the parent product modal state SHALL update to that new `purchase_tax_id`
- **AND** saving the new product SHALL persist that `purchase_tax_id`
- **AND** the emitted product payload into the purchase flow SHALL contain that same `purchase_tax_id`

#### Scenario: Newly created sale tax auto-selected in quick-add modal persists
- **WHEN** a user opens the add-product modal from purchase creation
- **AND** enables sale pricing
- **AND** creates a new tax from the nested sale tax quick-add modal
- **AND** the sale tax dropdown auto-selects the new tax
- **THEN** the parent product modal state SHALL update to that new `sale_tax_id`
- **AND** saving the new product SHALL persist that `sale_tax_id`

#### Scenario: Quick-add tax auto-selection is scoped to the requesting field
- **WHEN** both purchase tax and sale tax dropdowns are rendered in the add-product modal
- **AND** a user creates a new tax from one of those dropdowns
- **THEN** only the requesting dropdown SHALL auto-select the new tax
- **AND** the non-requesting dropdown SHALL preserve its prior selection state

#### Scenario: Quick-add modal must not present false tax selection state
- **WHEN** a tax dropdown in the add-product modal displays a tax as selected
- **THEN** the backing parent product modal property for that field SHALL hold the same tax id before save
- **AND** the saved product tax configuration SHALL not diverge from the visible selection the user saw

### Requirement: Non-PKP purchase creation ignores tax state entirely

When the active setting has `is_pkp = false`, purchase creation SHALL suppress tax UI and SHALL not persist any tax state.

#### Scenario: Non-PKP purchase hides tax UI
- **WHEN** a user opens the purchase create form and the active setting has `is_pkp = false`
- **THEN** the purchase tax selector SHALL NOT be visible
- **AND** the `is_tax_included` control SHALL NOT be visible

#### Scenario: Non-PKP purchase does not persist detail tax
- **WHEN** a user submits a purchase and the active setting has `is_pkp = false`
- **THEN** each saved purchase detail SHALL persist `tax_id = null`
- **AND** each saved purchase detail SHALL persist `product_tax_amount = 0`

#### Scenario: Non-PKP purchase does not persist header tax
- **WHEN** a user submits a purchase and the active setting has `is_pkp = false`
- **THEN** the saved purchase header SHALL persist `tax_amount = 0`
- **AND** the saved purchase SHALL persist `is_tax_included = 0`


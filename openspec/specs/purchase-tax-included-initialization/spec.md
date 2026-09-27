# purchase-tax-included-initialization Specification

## Purpose
TBD - created by archiving change fix-purchase-is-tax-included-state. Update Purpose after archive.
## Requirements
### Requirement: PKP purchase defaults to tax-included on create

When creating a new PKP purchase (not duplicating), the form SHALL default to `is_tax_included = true`, reflecting that PKP entities should consider taxes as included in prices by default.

#### Scenario: New PKP purchase creation
- **WHEN** user opens the purchase create form with a PKP setting
- **THEN** the "Termasuk Pajak" (tax included) checkbox SHALL appear checked

#### Scenario: New PKP purchase submission
- **WHEN** user submits a new PKP purchase without modifying the tax-included checkbox
- **THEN** the purchase SHALL be saved with `is_tax_included = 1` in the database

#### Scenario: User disables tax-included for PKP
- **WHEN** user unchecks the "Termasuk Pajak" checkbox before submission
- **THEN** the purchase SHALL be saved with `is_tax_included = 0` in the database

### Requirement: CreateForm and ProductCart synchronize is_tax_included on mount

The parent CreateForm component and child ProductCart component SHALL maintain synchronized state for `is_tax_included` immediately upon mount, ensuring consistency between the form UI and the data structure used at submission.

#### Scenario: Components sync on new purchase mount
- **WHEN** both CreateForm and ProductCart components mount during new purchase creation
- **THEN** CreateForm SHALL receive the ProductCart's initial `is_tax_included` value via the `taxIncludedUpdated` event

#### Scenario: PKP new purchase shows consistent state
- **WHEN** a new PKP purchase form is opened
- **THEN** the checkbox visual state AND the component data state AND the submitted database value SHALL all reflect `is_tax_included = true`

#### Scenario: Non-PKP purchase hides tax-included field
- **WHEN** user opens a purchase create form with a non-PKP setting
- **THEN** the "Termasuk Pajak" checkbox SHALL NOT be visible

#### Scenario: Non-PKP purchase stores false
- **WHEN** user submits a new non-PKP purchase
- **THEN** the purchase SHALL be saved with `is_tax_included = 0` in the database regardless of checkbox state

### Requirement: User checkbox changes update database state

When a user manually toggles the `is_tax_included` checkbox, the change SHALL propagate through the component hierarchy and be persisted correctly to the database.

#### Scenario: User checks tax-included before submit
- **WHEN** user opens a PKP purchase form and checks the tax-included checkbox (if unchecked initially)
- **THEN** submitting the form SHALL save `is_tax_included = 1`

#### Scenario: User unchecks tax-included before submit
- **WHEN** user opens a PKP purchase form and unchecks the tax-included checkbox
- **THEN** submitting the form SHALL save `is_tax_included = 0`

### Requirement: Purchase creation stores correct tax inclusion state
The system SHALL persist the `is_tax_included` field accurately based on the current form state at submission time, not the default property value.

#### Scenario: Tax-included state matches form checkbox at submission
- **WHEN** a purchase form is submitted
- **THEN** the saved purchase's `is_tax_included` value SHALL match the form's final checkbox state at the time of submission


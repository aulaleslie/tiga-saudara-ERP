## MODIFIED Requirements

### Requirement: POS SHALL offer business-scoped unit choice on each eligible product selection
Each name-result selection or base-unit barcode add SHALL show a unit picker when at least one conversion is sales-enabled for the acting business. The picker SHALL adapt the existing bundle-selection card presentation, display the base unit and enabled conversions with their factors, and keep the base unit selectable. Each conversion card SHALL display the acting business's configured conversion-unit price as a reference price when present, including its unit label. A conversion without a business-scoped price SHALL display an explicit missing-price state and SHALL NOT present zero or a synthesized price. The picker SHALL NOT represent the conversion reference price as a guaranteed final charge; existing packing, customer-tier, bundle, tax, rounding, and override rules remain authoritative. Disabled conversions SHALL NOT be selectable. Missing business conversion-price rows SHALL retain existing enabled-by-default semantics.

#### Scenario: Name selection offers units
- **WHEN** a cashier selects a product by name with sales-enabled BOX factor 12 and a configured conversion price of Rp120.000 for the acting business
- **THEN** the picker offers the base unit adding 1 and BOX adding 12 base units
- **AND** the BOX card displays `Harga konversi: Rp120.000 / BOX`
- **AND** no cart mutation occurs before the selection flow completes

#### Scenario: Base barcode prompts every time
- **WHEN** a cashier scans the base barcode again after completing an earlier add
- **THEN** the picker appears again even if the product already exists in the cart
- **AND** the previous unit choice is not automatically reused

#### Scenario: Repeated name selection prompts again
- **WHEN** a cashier selects the same eligible name result again
- **THEN** a fresh unit choice is required

#### Scenario: No sales-enabled conversions
- **WHEN** a product has no conversions or all conversions are sales-disabled for the acting business
- **THEN** the unit picker is skipped and the existing base-unit add flow continues

#### Scenario: Business eligibility and price differ
- **WHEN** BOX is disabled for sales in business A but enabled with price Rp120.000 in business B
- **THEN** BOX is unavailable in A and available with the Rp120.000 reference price in B regardless of purchase enablement

#### Scenario: Missing business conversion-price row
- **WHEN** the acting business has no price row for a product conversion
- **THEN** unit eligibility uses the existing enabled default and pricing retains its existing fallback behavior
- **AND** the conversion card displays an explicit missing-price state rather than `Rp0` or a synthesized price

#### Scenario: Conversion reference differs from final charge
- **WHEN** an eligible conversion card displays its configured business-scoped conversion price and existing customer-tier, packing, or bundle rules produce a different cart amount
- **THEN** the cart uses its existing authoritative calculation
- **AND** the picker does not claim that the conversion reference price is the guaranteed final charge

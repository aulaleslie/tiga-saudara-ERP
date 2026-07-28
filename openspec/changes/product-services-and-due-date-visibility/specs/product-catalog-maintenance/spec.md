## ADDED Requirements

### Requirement: Standard product prices SHALL accept optional non-negative values
The standard Product Create and Edit flows SHALL allow `purchase_price`, `sale_price`, `tier_1_price`, and `tier_2_price` to be omitted or set to zero when their purchase or sale toggles are enabled. Each supplied value MUST be numeric and MUST NOT be less than zero.

#### Scenario: Enabled purchase and sale settings save zero prices
- **WHEN** a user enables purchasing and selling for a product and submits zero for every configured purchase and sale price
- **THEN** the product is saved successfully with zero-valued setting-scoped prices

#### Scenario: Enabled price field is left blank
- **WHEN** a user enables purchasing or selling and leaves one of its price fields blank
- **THEN** the product is saved successfully and existing persistence defaults store the omitted value as zero where applicable

#### Scenario: Negative product price is rejected
- **WHEN** a user submits any configured purchase, sale, or tier sale price below zero
- **THEN** validation rejects the submission with an error for that price field

### Requirement: Zero-stock products SHALL support disabling stock management
The Product Edit flow SHALL permit a product with no positive `ProductStock` quantity in any setting to disable stock management and serial tracking. The server MUST reject the transition when any product-stock row has a positive quantity.

#### Scenario: Product with zero stock disables stock management
- **WHEN** a user edits a product whose stock quantity is zero in every setting and clears stock management
- **THEN** the edit saves successfully without requiring a base unit or conversion unit
- **AND** serial-number-required is stored as false

#### Scenario: Product with positive stock remains protected
- **WHEN** a product has a positive stock quantity in any setting
- **THEN** stock management cannot be disabled through the edit flow

### Requirement: Disabling stock management SHALL delete conversions atomically
When a valid product edit disables stock management, the system SHALL delete all of that product's unit conversions, setting-scoped conversion prices, and conversion barcode identities within the product update transaction. Stale conversion inputs MUST NOT cause conversion validation errors for that transition.

#### Scenario: Existing conversions are removed when stock management is disabled
- **WHEN** a zero-stock product with unit conversions and conversion prices is saved with stock management disabled
- **THEN** its conversion rows and conversion-price rows are removed
- **AND** their barcode identities are released

#### Scenario: Stale conversion input does not block disabling stock management
- **WHEN** the edit submission contains blank or stale conversion fields while stock management is disabled
- **THEN** the submission does not fail `unit_id`, conversion factor, or conversion-price validation because of those fields

## ADDED Requirements

### Requirement: POS cart line targeting SHALL include bundle state
When adding or updating a POS cart line for a bundle-parent product, the system SHALL identify the target cart row by product and bundle state. A selected bundle id, a different selected bundle id, and no selected bundle MUST be treated as distinct line identities.

#### Scenario: Same product and same selected bundle merges
- **WHEN** the cart contains Product A with Bundle A
- **AND** the cashier adds Product A and chooses Bundle A again
- **THEN** the system MUST target the existing Product A with Bundle A row
- **AND** the system MUST increment quantity or append the scanned serial on that row according to the product's serial tracking behavior

#### Scenario: Same product and different selected bundle does not merge
- **WHEN** the cart contains Product A with Bundle A
- **AND** the cashier adds Product A and chooses Bundle B
- **THEN** the system MUST create or target a Product A with Bundle B row
- **AND** the system MUST NOT increment or append serials on the Product A with Bundle A row

#### Scenario: Same product without bundle does not merge into selected bundle
- **WHEN** the cart contains Product A with Bundle A
- **AND** the cashier adds Product A and explicitly continues without a bundle
- **THEN** the system MUST create or target a Product A row without bundle metadata
- **AND** the system MUST NOT increment or append serials on the Product A with Bundle A row

#### Scenario: Bundle-aware rows coexist in one cart
- **WHEN** the cashier adds Product A with Bundle A, Product A with Bundle B, and Product A without a bundle
- **THEN** the cart snapshot MUST expose three distinct rows for the same parent product
- **AND** each row MUST retain its own quantity and assigned serial list

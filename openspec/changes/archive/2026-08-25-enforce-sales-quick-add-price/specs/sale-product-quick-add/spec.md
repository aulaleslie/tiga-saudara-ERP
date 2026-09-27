## MODIFIED Requirements

### Requirement: Sales product quick-add must create a sellable sales line
When a user opens product quick-add from a sales page and saves a product into the sales cart, the flow SHALL force the product into a sellable context and SHALL require a numeric sale price greater than zero before creating the product or inserting it into the cart. Missing, zero, negative, or non-numeric sale prices SHALL be rejected with validation feedback on the sale-price field.

#### Scenario: Sales quick-add requires positive sellable pricing before cart insertion
- **WHEN** a user opens product quick-add from the sales create or sales edit page
- **AND** attempts to save with a missing, zero, negative, or non-numeric sale price
- **THEN** the flow SHALL report a validation error for `sale_price`
- **AND** the system SHALL NOT create the product or insert it into the sales cart

#### Scenario: Sales quick-add accepts valid sellable pricing
- **WHEN** a user opens product quick-add from the sales create or sales edit page
- **AND** submits a numeric sale price greater than zero with otherwise valid product data
- **THEN** the flow SHALL create the product as sellable
- **AND** the flow SHALL insert the product into the sales cart using its active-business price metadata

#### Scenario: Purchase-only defaults do not leak into sales quick-add
- **WHEN** the shared product quick-add modal is opened from a sales page
- **THEN** the sales flow SHALL force the sellable state
- **AND** it SHALL NOT allow purchase-only defaults to bypass the positive sale-price requirement

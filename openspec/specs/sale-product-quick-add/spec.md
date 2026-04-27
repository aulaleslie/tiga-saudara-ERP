## ADDED Requirements

### Requirement: Sales product quick-add must create a sellable sales line
When a user opens product quick-add from a sales page and saves a product into the sales cart, the flow SHALL not silently create a purchase-only product and insert it into the sales cart without sellable pricing.

#### Scenario: Sales quick-add requires sellable pricing before cart insertion
- **WHEN** a user opens product quick-add from the sales create or sales edit page
- **AND** attempts to save the product into the sales cart
- **THEN** the flow SHALL ensure the product is marked sellable for that sales context
- **AND** the flow SHALL require valid sales pricing before inserting the product into the sales cart

#### Scenario: Purchase-only defaults do not leak into sales quick-add
- **WHEN** the shared product quick-add modal is opened from a sales page
- **THEN** the sales flow SHALL NOT default to a purchase-only product state that can be saved directly into the sales cart

### Requirement: Sales quick-add uses setting-scoped sales pricing after save
After a product is created from the sales quick-add flow and inserted into the sales cart, the displayed line price SHALL be resolved from the active setting's `product_prices` row for that product.

#### Scenario: Newly quick-added product appears at base sales price
- **WHEN** a user creates a new product from the sales quick-add flow
- **AND** no customer tier is currently selected
- **THEN** the inserted cart line SHALL display the active setting's base `sale_price`
- **AND** the line SHALL retain the corresponding tier metadata for later repricing

#### Scenario: Newly quick-added product reprices after tiered customer selection
- **WHEN** a user creates a new product from the sales quick-add flow
- **AND** later selects a customer with tier `WHOLESALER` or `RESELLER`
- **THEN** the inserted cart line SHALL reprice using the same tier rules as an existing product added from sales search

### Requirement: Sales quick-add communicates pricing intent clearly
The sales UI SHALL make it clear that the product being added from the sales quick-add flow is entering a sales cart with sellable pricing, so users do not misinterpret product setup issues as cart pricing bugs.

#### Scenario: Sales quick-add exposes sales-oriented pricing fields
- **WHEN** a user opens product quick-add from the sales page
- **THEN** the modal SHALL present the selling-price inputs needed for sales cart insertion in that context
- **AND** the user SHALL be able to identify that the product is being prepared for sale, not only for purchase

#### Scenario: Sales quick-add does not report a misleading cart success
- **WHEN** a user submits product quick-add from the sales page without meeting the sales pricing requirements
- **THEN** the system SHALL block cart insertion and present validation feedback
- **AND** the system SHALL NOT display a success state that implies the product was correctly inserted with valid sales pricing

### Requirement: Sales quick-add flow SHALL allow sequential additions without manual refresh

The sales-specific quick-add flow SHALL ensure that all sales-related inputs (Sale Price, Sale Tax, Tier Prices) are completely refreshed and ready for a new entry immediately after a successful cart insertion, without requiring a manual page reload or manual clearing of fields.

#### Scenario: Sales-specific fields refresh after each quick-add
- **WHEN** a user successfully adds a product to the sales cart via the quick-add modal
- **THEN** the modal SHALL remain ready for another addition
- **AND** the `sale_price` and `sale_tax_id` SHALL be reset to defaults
- **AND** any tier pricing configured in the modal SHALL be cleared.


## ADDED Requirements

### Requirement: Sales cart uses setting-scoped product prices for new lines
When a product is added to the sales cart on create or edit pages, the system SHALL derive the line's base and tier pricing from the active setting's `product_prices` record for that product.

#### Scenario: Add existing product before customer selection
- **WHEN** a user opens the sales create or sales edit page
- **AND** no customer tier is currently selected
- **AND** the user adds an existing product to the sales cart
- **THEN** the cart line SHALL use the active setting's base `sale_price` as the displayed unit price
- **AND** the cart SHALL retain the corresponding `sale_price`, `tier_1_price`, and `tier_2_price` metadata for later repricing

#### Scenario: Missing legacy product sale columns do not change sales line pricing
- **WHEN** a product's legacy `products.sale_price`, `tier_1_price`, and `tier_2_price` columns are empty, zero, or stale
- **AND** the active setting has a valid `product_prices` row for that product
- **THEN** the sales cart SHALL use the `product_prices` values
- **AND** the legacy product columns SHALL NOT override the cart line pricing

### Requirement: Selecting a customer reprices existing sales cart lines
When an active sales customer is selected or changed, the sales cart SHALL re-evaluate existing lines against the customer's tier and update line pricing accordingly.

#### Scenario: Existing customer selected after products already added
- **WHEN** one or more products already exist in the sales cart
- **AND** the user selects an existing customer with tier `WHOLESALER` or `RESELLER`
- **THEN** each existing cart line SHALL be repriced using that customer's tier price from the active setting's `product_prices` row
- **AND** each line's subtotal metadata SHALL be recalculated from the new unit price

#### Scenario: Customer without tier selected after products already added
- **WHEN** one or more products already exist in the sales cart
- **AND** the user selects a customer without a pricing tier
- **THEN** each existing cart line SHALL use the active setting's base `sale_price`
- **AND** any prior tier-based repricing SHALL be removed

### Requirement: Customer quick-add selection triggers the same repricing flow
When a customer is created from the sales page and becomes the active customer selection, the sales cart SHALL execute the same repricing behavior as if the user had selected an existing customer from the dropdown.

#### Scenario: Quick-add customer with wholesaler tier
- **WHEN** a user has one or more products in the sales cart
- **AND** the user creates a new customer from the sales page with tier `WHOLESALER`
- **AND** that customer becomes the selected sales customer
- **THEN** the sales cart SHALL reprice existing lines to the active setting's `tier_1_price`

#### Scenario: Quick-add customer with reseller tier
- **WHEN** a user has one or more products in the sales cart
- **AND** the user creates a new customer from the sales page with tier `RESELLER`
- **AND** that customer becomes the selected sales customer
- **THEN** the sales cart SHALL reprice existing lines to the active setting's `tier_2_price`

### Requirement: Sales edit cart hydration preserves current pricing semantics
When an existing sale is opened in edit mode, the cart SHALL hydrate pricing metadata from the same setting-scoped source used for newly added sales lines.

#### Scenario: Edit page restores line pricing metadata
- **WHEN** a user opens a sales edit page with existing sale lines
- **THEN** each hydrated cart line SHALL carry `sale_price`, `tier_1_price`, and `tier_2_price` metadata resolved from the active setting's `product_prices` row for that product
- **AND** the sales edit flow SHALL NOT depend on legacy product sale columns as the authoritative pricing source

#### Scenario: Edit page reprices after customer change
- **WHEN** a user opens a sales edit page with existing lines
- **AND** changes the active customer to a customer with a different tier state
- **THEN** the existing cart lines SHALL reprice using the same rules as sales create
- **AND** the repriced lines SHALL use the active setting's current tier metadata

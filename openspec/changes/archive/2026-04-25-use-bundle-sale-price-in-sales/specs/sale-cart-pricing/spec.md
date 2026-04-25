## ADDED Requirements

### Requirement: Sales bundled rows initialize from configured bundle sale price
When a user selects a product bundle in Sales create or Sales edit cart flows, the system SHALL initialize the parent cart row's unit price from `product_bundles.bundle_sale_price` and treat that value as the billable price for the parent product plus selected bundle.

#### Scenario: Selecting a bundle initializes parent row price
- **WHEN** a user adds a product to the Sales cart
- **AND** selects a product bundle whose `bundle_sale_price` is set
- **THEN** the parent cart row SHALL use `bundle_sale_price` as the displayed and billable unit price
- **AND** the cart row subtotal SHALL be calculated from that parent row unit price and row quantity

#### Scenario: Legacy bundle add-on price is ignored
- **WHEN** a selected product bundle has both legacy `product_bundles.price` and new `product_bundles.bundle_sale_price` values
- **THEN** Sales cart pricing SHALL use `bundle_sale_price`
- **AND** Sales cart pricing SHALL NOT add legacy `product_bundles.price` to the parent product price

#### Scenario: Bundled row price remains editable
- **WHEN** a Sales cart row has a selected bundle
- **AND** the user manually changes the parent row price
- **THEN** the cart SHALL preserve the edited parent row price as the billable unit price
- **AND** the row subtotal and sale total SHALL recalculate from the edited parent row price

### Requirement: Sales bundled rows SHALL bypass automatic product repricing
When a Sales cart row has a selected bundle, the system SHALL preserve the row's current parent row price during customer, quantity, tax, discount, and cart reconciliation flows instead of replacing it with customer tier pricing or cascading quantity pricing.

#### Scenario: Customer tier selection does not reprice bundled rows
- **WHEN** a Sales cart contains a bundled row with a current parent row price
- **AND** the user selects or changes a customer with tier `WHOLESALER` or `RESELLER`
- **THEN** the bundled row SHALL keep its current parent row price
- **AND** only non-bundled rows SHALL be eligible for customer tier repricing

#### Scenario: Quantity change preserves bundled row unit price
- **WHEN** a Sales cart row has a selected bundle
- **AND** the user changes the row quantity
- **THEN** the row unit price SHALL remain unchanged
- **AND** the row subtotal SHALL recalculate as the current parent row price multiplied by the new quantity, with applicable tax and discounts
- **AND** cascading quantity pricing SHALL NOT replace the bundled row unit price

#### Scenario: Tax and discount recalculation preserves bundled row unit price
- **WHEN** a Sales cart row has a selected bundle
- **AND** the user changes tax inclusion, line tax, line discount, or global discount
- **THEN** the recalculation SHALL preserve the current parent row unit price
- **AND** totals SHALL be recalculated from that preserved price

### Requirement: Sales bundle component prices SHALL be informational only
When Sales creates, updates, hydrates, or persists a sale with selected bundle components, component item prices SHALL NOT contribute to cart row subtotals, `sale_details` subtotals, sale header totals, or payment due totals.

#### Scenario: Bundle component informational prices do not add to totals
- **WHEN** a selected bundle contains component items with `informational_item_price` values
- **THEN** Sales SHALL NOT add those component prices to the parent cart row subtotal
- **AND** Sales SHALL NOT add those component prices to the sale total

#### Scenario: Bundle component prices are not editable in Sales cart
- **WHEN** a Sales cart row has selected bundle components
- **THEN** the Sales cart SHALL hide or show component item prices as read-only informational data
- **AND** the user SHALL NOT be able to edit component item prices from the Sales cart

#### Scenario: Persisted bundle component rows are non-billable
- **WHEN** Sales create or update persists `sale_bundle_items` for a selected bundle
- **THEN** the persisted bundle component rows SHALL not contain billable subtotal amounts that accumulate into sale totals
- **AND** billable amounts SHALL remain represented by the parent `sale_details` row

## MODIFIED Requirements

### Requirement: Selecting a customer reprices existing sales cart lines
When an active sales customer is selected or changed, the sales cart SHALL re-evaluate existing non-bundled lines against the customer's tier and update line pricing accordingly. Cart rows with selected bundles SHALL preserve their current parent row price and SHALL NOT be repriced from customer tier prices while the bundle remains selected.

#### Scenario: Existing customer selected after products already added
- **WHEN** one or more products already exist in the sales cart
- **AND** the user selects an existing customer with tier `WHOLESALER` or `RESELLER`
- **THEN** each existing non-bundled cart line SHALL be repriced using that customer's tier price from the active setting's `product_prices` row
- **AND** each repriced non-bundled line's subtotal metadata SHALL be recalculated from the new unit price
- **AND** each existing bundled cart line SHALL preserve its current parent row price

#### Scenario: Customer without tier selected after products already added
- **WHEN** one or more products already exist in the sales cart
- **AND** the user selects a customer without a pricing tier
- **THEN** each existing non-bundled cart line SHALL use the active setting's base `sale_price`
- **AND** any prior tier-based repricing SHALL be removed from non-bundled rows
- **AND** each existing bundled cart line SHALL preserve its current parent row price

### Requirement: Sales edit cart hydration preserves current pricing semantics
When an existing sale is opened in edit mode, the cart SHALL hydrate pricing metadata from the same setting-scoped source used for newly added sales lines. For existing sale lines with selected bundle components, the cart SHALL hydrate the parent row's persisted sale detail price as the current editable bundled row price and SHALL treat component item prices as non-billable context.

#### Scenario: Edit page restores line pricing metadata
- **WHEN** a user opens a sales edit page with existing sale lines
- **THEN** each hydrated cart line SHALL carry `sale_price`, `tier_1_price`, and `tier_2_price` metadata resolved from the active setting's `product_prices` row for that product
- **AND** the sales edit flow SHALL NOT depend on legacy product sale columns as the authoritative pricing source

#### Scenario: Edit page restores bundled row pricing
- **WHEN** a user opens a sales edit page with an existing sale line that has selected bundle components
- **THEN** the hydrated bundled cart row SHALL use the persisted parent sale detail price as its current editable row price
- **AND** the hydrated bundle component prices SHALL NOT add to the cart row subtotal
- **AND** the hydrated cart row SHALL remain marked as bundled for repricing bypass behavior

#### Scenario: Edit page reprices after customer change
- **WHEN** a user opens a sales edit page with existing lines
- **AND** changes the active customer to a customer with a different tier state
- **THEN** the existing non-bundled cart lines SHALL reprice using the same rules as sales create
- **AND** the repriced non-bundled lines SHALL use the active setting's current tier metadata
- **AND** existing bundled cart lines SHALL preserve their current parent row prices

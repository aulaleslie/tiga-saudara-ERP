## Purpose

Define how Sales cart pricing resolves product base and tier prices from setting-scoped product_prices records, handles customer-driven repricing, manages bundle pricing and component informational values, and applies row-total rounding to automatically priced rows.
## Requirements
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

### Requirement: Sales bundled rows SHALL bypass automatic product repricing
When a Sales cart row has a selected bundle, the system SHALL preserve its current editable parent row price during customer, quantity, tax, discount, and cart reconciliation flows instead of replacing it with product or bundle definition prices.

#### Scenario: Quantity change preserves overridden bundled row unit price
- **WHEN** a Sales bundled row has a user-configured parent row price
- **AND** the user changes the row quantity
- **THEN** the row unit price SHALL remain unchanged
- **AND** the row subtotal SHALL recalculate from that price and the new quantity
- **AND** cascading quantity pricing SHALL NOT replace the bundled row price

#### Scenario: Tax and discount recalculation preserves bundled row unit price
- **WHEN** a Sales bundled row has a user-configured parent row price
- **AND** tax inclusion, line tax, row discount, or global discount changes
- **THEN** recalculation SHALL preserve that parent row unit price
- **AND** the resulting totals SHALL be derived from the preserved price

#### Scenario: Component snapshots remain fixed after parent price override
- **WHEN** a user changes the bundled parent row sale price
- **THEN** the bundle component informational-price snapshots SHALL remain unchanged
- **AND** no component price SHALL be proportionally repriced from the parent override

### Requirement: Sales bundle component prices SHALL be informational only
When Sales creates, updates, hydrates, discounts, or persists a sale with selected bundle components, component informational prices SHALL NOT contribute to cart row subtotals, `sale_details` subtotals, sale header totals, payment due totals, or discount target counts.

#### Scenario: Component informational prices do not add to totals
- **WHEN** a selected bundle contains component informational-price snapshots
- **THEN** Sales SHALL NOT add those values to the parent cart row subtotal
- **AND** Sales SHALL NOT add those values to the Sale total

#### Scenario: Component prices remain read-only in Sales cart
- **WHEN** a Sales cart row contains selected bundle components
- **THEN** component prices SHALL be hidden or displayed read-only
- **AND** the user SHALL NOT be able to edit component prices from the Sales cart

#### Scenario: Persisted components remain non-billable
- **WHEN** Sales creates or updates `sale_bundle_items` for a selected bundle
- **THEN** the component rows SHALL persist zero non-billable commercial price and subtotal values
- **AND** the parent `sale_details` row SHALL remain the complete commercial representation

#### Scenario: Parent price override does not make components billable
- **WHEN** a user overrides the bundled parent row price
- **THEN** the overridden price SHALL remain entirely represented by the parent Sale row
- **AND** component rows SHALL remain non-billable

### Requirement: Sales discounts SHALL target commercial parent rows
Normal Sales row and global discounts SHALL operate on commercial Sale rows only and SHALL NOT treat bundle component rows as separate discount targets.

#### Scenario: Bundle row discount reduces only the parent row
- **WHEN** a user applies a row discount to a bundled Sale row
- **THEN** the discount SHALL reduce only that parent row's commercial amount
- **AND** component informational prices and non-billable component rows SHALL remain unchanged

#### Scenario: Global discount is prorated across commercial Sale rows
- **WHEN** a Sale contains multiple commercial item rows and a global discount
- **THEN** the global discount SHALL be prorated across those commercial rows using the established Sales transaction rounding convention
- **AND** bundle component rows SHALL NOT increase the number of proration targets

#### Scenario: Bundled row global share reduces only its parent
- **WHEN** a bundled commercial row receives a share of the global discount
- **THEN** that share SHALL reduce only the bundle parent row
- **AND** its component informational prices SHALL remain unchanged
- **WHEN** Sales create or update persists `sale_bundle_items` for a selected bundle
- **THEN** the persisted bundle component rows SHALL not contain billable subtotal amounts that accumulate into sale totals
- **AND** billable amounts SHALL remain represented by the parent `sale_details` row

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

### Requirement: Sales bundled rows apply row-total rounding to automatically priced visible totals
When a user selects a product bundle in Sales create or Sales edit cart flows and the resulting automatically priced visible row is calculated through an eligible user interaction, Sales SHALL round its final tax-inclusive row total using the effective business configuration without modifying bundle component informational values or affecting manual unit-price or manual-line-total overrides.

#### Scenario: Automatic bundle rows receive row-total rounding
- **WHEN** a user adds a product to the Sales cart
- **AND** selects a product bundle whose `bundle_sale_price` is set
- **AND** the row calculation results in an automatically priced visible total
- **THEN** the final tax-inclusive visible row total SHALL use configured row-total rounding
- **AND** bundle component informational prices SHALL remain unchanged
- **AND** the rounding difference SHALL be absorbed by the parent row total

#### Scenario: Manual bundle row price override bypasses rounding
- **WHEN** a Sales cart row has a selected bundle
- **AND** the user manually changes the parent row price or row total
- **THEN** the row SHALL NOT receive automatic row-total rounding
- **AND** the user-edited price SHALL be preserved exactly as entered

#### Scenario: Bundle component prices remain non-billable after rounding
- **WHEN** an automatically priced visible bundle row changes from `78999.00` to a rounded total of `79000.00`
- **THEN** each bundle component informational price SHALL retain its existing value
- **AND** the rounding difference SHALL NOT be distributed into component prices
- **AND** components SHALL remain non-billable context rows


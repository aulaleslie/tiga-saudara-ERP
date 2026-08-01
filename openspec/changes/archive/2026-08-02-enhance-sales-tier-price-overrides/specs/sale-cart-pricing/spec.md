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

### Requirement: Automatic Sales pricing handles absent setting-scoped price rows visibly
When Sales needs to resolve an automatic non-bundled line for the effective business and that product has no `product_prices` row for that business, the system SHALL use zero as the line's automatic unit price and SHALL not fall back to legacy or another business's selling prices. The system SHALL issue one consolidated actionable notification for all missing prices found in that resolution operation.

#### Scenario: Product is added with no effective-business price row
- **WHEN** a user adds a standard non-bundled product to the Sales cart for an effective business that has no `product_prices` row for that product
- **THEN** the new line SHALL have automatic zero unit price and recalculated zero line total
- **AND** the system SHALL notify the user that the product has no configured price for that business

#### Scenario: Customer change finds multiple missing prices
- **WHEN** a customer selection or change resolves multiple automatic non-bundled cart lines without an effective-business `product_prices` row
- **THEN** each affected line SHALL have automatic zero unit price
- **AND** the system SHALL display one notification naming the affected products and effective business
- **AND** the system SHALL not use legacy product selling-price columns as a fallback

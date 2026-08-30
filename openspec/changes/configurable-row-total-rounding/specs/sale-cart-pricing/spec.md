## MODIFIED Requirements

### Requirement: Sales bundled rows initialize from configured bundle sale price
When a user selects a product bundle in Sales create or Sales edit cart flows, the system SHALL initialize the parent cart row's unit price from `product_bundles.bundle_sale_price` and treat that value as the billable price for the parent product plus selected bundle. When this automatically priced visible row is calculated through an eligible user interaction, Sales SHALL round its final tax-inclusive row total using the effective business configuration without modifying bundle component informational values.

#### Scenario: Selecting a bundle initializes parent row price
- **WHEN** a user adds a product to the Sales cart
- **AND** selects a product bundle whose `bundle_sale_price` is set
- **THEN** the parent cart row SHALL use `bundle_sale_price` as the displayed unit price
- **AND** the cart row subtotal SHALL be calculated from that parent row unit price and row quantity
- **AND** the final automatic tax-inclusive subtotal SHALL use configured row-total rounding

#### Scenario: Legacy bundle add-on price is ignored
- **WHEN** a selected product bundle has both legacy `product_bundles.price` and new `product_bundles.bundle_sale_price` values
- **THEN** Sales cart pricing SHALL use `bundle_sale_price`
- **AND** Sales cart pricing SHALL NOT add legacy `product_bundles.price` to the parent product price

#### Scenario: Bundled row price remains editable
- **WHEN** a Sales cart row has a selected bundle
- **AND** the user manually changes the parent row price
- **THEN** the cart SHALL preserve the edited parent row price as the billable unit price
- **AND** the row subtotal and sale total SHALL recalculate from the edited parent row price without automatic row-total rounding

#### Scenario: Bundle component informational values remain unchanged
- **WHEN** an automatically priced visible bundle row changes from `78999.00` to a rounded total of `79000.00`
- **THEN** each bundle component informational price SHALL retain its existing value
- **AND** Sales SHALL NOT distribute the rounding difference into component informational values


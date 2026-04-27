## ADDED Requirements

### Requirement: POS SHALL use bundle sale price as selected bundle row price
When a cashier selects a bundle for a POS cart line, the system SHALL use `product_bundles.bundle_sale_price` as the bundled parent row unit price and MUST NOT add legacy `product_bundles.price` to the parent product price.

#### Scenario: Selected bundle row uses final bundle sale price
- **WHEN** a cashier adds a bundle-parent product with a selected bundle
- **THEN** the POS cart snapshot line unit price SHALL equal the selected bundle's `bundle_sale_price`
- **AND** the line total SHALL be calculated from that unit price multiplied by the sold bundle quantity

#### Scenario: Legacy bundle add-on is ignored
- **WHEN** a selected bundle has both `bundle_sale_price` and legacy `price` values
- **THEN** POS bundled row pricing SHALL use `bundle_sale_price`
- **AND** POS bundled row pricing SHALL NOT add the legacy `price` value to the parent product or tier price

### Requirement: POS bundled rows SHALL bypass customer tier repricing
Selected bundled POS cart rows SHALL preserve their bundle sale price through customer selection changes while non-bundled rows continue to use existing customer tier repricing behavior.

#### Scenario: Customer tier change preserves bundled row price
- **WHEN** a POS cart contains a selected bundled row priced from `bundle_sale_price`
- **AND** the cashier selects or changes the cart customer to a customer with tier pricing
- **THEN** the selected bundled row unit price SHALL remain the bundle sale price
- **AND** the row SHALL NOT be repriced from parent product tier prices

#### Scenario: Non-bundled row still reprices by tier
- **WHEN** a POS cart contains a normal non-bundled product row
- **AND** the cashier selects or changes the cart customer to a customer with tier pricing
- **THEN** the normal product row SHALL continue to follow existing POS customer tier repricing behavior

### Requirement: POS SHALL treat component informational prices as internal allocation data
Bundle component informational prices SHALL be available to POS checkout allocation logic but MUST NOT become customer-facing billable component prices in POS UI, cart totals, checkout totals, receipt lines, or persisted `sale_bundle_items` monetary totals.

#### Scenario: Component prices do not appear as billable POS lines
- **WHEN** a POS cart contains a selected bundle with component informational prices
- **THEN** the POS customer-facing cart, checkout, and receipt totals SHALL remain based on the single bundled parent row price
- **AND** the component informational prices SHALL NOT be displayed as separate billable POS component prices

#### Scenario: Component persistence remains non-billable
- **WHEN** POS checkout persists bundle composition for a selected bundled row
- **THEN** the persisted `sale_bundle_items` records SHALL remain linked to the parent sale detail
- **AND** their `price` and `sub_total` monetary values SHALL remain non-billable context rather than allocated revenue totals

### Requirement: POS SHALL fallback missing component allocation price to product sale price
When a bundled component has no `informational_item_price`, POS allocation SHALL use the component product's active-setting `product_prices.sale_price` as the allocation amount and MUST NOT use legacy `product_bundle_items.price`.

#### Scenario: Missing informational price uses active product sale price
- **WHEN** POS checkout allocates revenue for a selected bundled component with no `informational_item_price`
- **AND** the component product has an active-setting sale price
- **THEN** the component allocation amount SHALL equal that active-setting product sale price

#### Scenario: Legacy item price is not used as allocation fallback
- **WHEN** POS checkout allocates revenue for a selected bundled component with no `informational_item_price`
- **AND** the component also has a legacy `product_bundle_items.price`
- **THEN** POS allocation SHALL NOT use the legacy item price as the fallback allocation amount

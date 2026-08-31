## MODIFIED Requirements

### Requirement: POS SHALL use bundle sale price as selected bundle row price
When a cashier selects a bundle, POS SHALL initialize the parent row from `product_bundles.bundle_sale_price`, apply configured automatic tax-inclusive row-total rounding to the customer-facing row, allow the cashier to override that parent row price, and preserve the captured transaction price without adding legacy bundle prices. POS SHALL leave captured component informational allocations unchanged and SHALL assign the difference between the rounded customer total and those allocations to the parent residual.

#### Scenario: Selected bundle initializes from configured bundle price
- **WHEN** a cashier selects a bundle whose automatic tax-inclusive row amount is `78999.00` under a `100.00` increment
- **THEN** the customer-facing row total SHALL be `79000.00`
- **AND** legacy `product_bundles.price` SHALL NOT be added

#### Scenario: Cashier override becomes captured customer price
- **WHEN** a cashier changes the bundled parent row price
- **THEN** POS SHALL preserve the overridden value as the customer-facing unit price
- **AND** cart, checkout, and receipt totals SHALL use that captured value without automatic row-total rounding

#### Scenario: Parent override leaves component allocations fixed
- **WHEN** a cashier changes the bundled parent row price
- **THEN** component informational allocation snapshots SHALL remain unchanged
- **AND** the parent residual SHALL absorb the entire difference

#### Scenario: Automatic rounding leaves component allocations fixed
- **WHEN** an automatic bundled customer row rounds from `78999.00` to `79000.00`
- **AND** one captured component allocation is `8999.00`
- **THEN** the component allocation SHALL remain `8999.00`
- **AND** the parent residual settlement SHALL be `70001.00`
- **AND** aggregate transaction settlement SHALL equal `79000.00`

#### Scenario: Price below component allocations is rejected
- **WHEN** the captured bundled row amount is less than the sum of its fixed component allocations
- **THEN** preflight or finalize SHALL reject the checkout with an actionable negative-residual validation error


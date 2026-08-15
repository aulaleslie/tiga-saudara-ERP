## MODIFIED Requirements

### Requirement: POS SHALL use bundle sale price as selected bundle row price
When a cashier selects a bundle, POS SHALL initialize the parent row from `product_bundles.bundle_sale_price`, allow the cashier to override that parent row price, and preserve the captured transaction price without adding legacy bundle prices.

#### Scenario: Selected bundle initializes from configured bundle price
- **WHEN** a cashier selects a bundle for a POS parent row
- **THEN** the row SHALL initialize from `bundle_sale_price`
- **AND** legacy `product_bundles.price` SHALL NOT be added

#### Scenario: Cashier override becomes captured customer price
- **WHEN** a cashier changes the bundled parent row price
- **THEN** POS SHALL preserve the overridden value as the customer-facing unit price
- **AND** cart, checkout, and receipt totals SHALL use that captured value

#### Scenario: Parent override leaves component allocations fixed
- **WHEN** a cashier changes the bundled parent row price
- **THEN** component informational allocation snapshots SHALL remain unchanged
- **AND** the parent residual SHALL absorb the entire difference

#### Scenario: Price below component allocations is rejected
- **WHEN** the captured bundled row amount is less than the sum of its fixed component allocations
- **THEN** preflight or finalize SHALL reject the checkout with an actionable negative-residual validation error

### Requirement: POS SHALL treat component informational prices as internal allocation data
POS SHALL allocate bundle component revenue from the POS transaction owner's captured bundle-item snapshots and SHALL NOT reload current product prices or use a stock owner's sale price.

#### Scenario: POS-owner saved snapshot supplies component allocation
- **WHEN** POS captures a selected bundle belonging to the transaction setting
- **THEN** each component allocation SHALL equal that bundle copy's saved `informational_item_price` multiplied by component and parent quantities
- **AND** the allocation SHALL remain stable through preflight and finalize

#### Scenario: Stock owner does not reprice component revenue
- **WHEN** a component is fulfilled by a setting different from the POS transaction owner
- **THEN** the source-owner Sales document SHALL receive the allocation captured from the POS owner's bundle snapshot
- **AND** the component source owner's current sale price SHALL NOT replace it

#### Scenario: Saved zero does not trigger live fallback
- **WHEN** a captured bundle component has a saved informational price of zero
- **THEN** POS SHALL preserve zero as the internal allocation
- **AND** POS SHALL NOT query a current component product price as fallback

#### Scenario: Bundle quantities scale from base-unit parent quantity
- **WHEN** a bundled parent row has outgoing base-unit quantity greater than one
- **THEN** each component's allocation quantity SHALL equal parent base-unit quantity multiplied by configured quantity per bundle
- **AND** an already-expanded component quantity SHALL NOT be expanded again

## ADDED Requirements

### Requirement: POS SHALL keep bundle components zero-priced for customers
Internal component allocations MUST NOT become separate customer charges in POS cart, checkout, receipt, or transaction-detail presentation.

#### Scenario: Receipt shows full captured bundle price on parent
- **WHEN** a bundled POS transaction is displayed or printed
- **THEN** the parent bundle row SHALL show the complete captured customer price
- **AND** each component SHALL show zero or an equivalent free/included presentation

#### Scenario: Internal owner allocation does not change customer total
- **WHEN** a captured bundle is decomposed across owner Sales documents
- **THEN** customer totals SHALL remain based on the single parent bundle row
- **AND** internal component allocations SHALL not be added again

### Requirement: POS bundle checkout SHALL remain discount-free
This change SHALL preserve the current POS contract in which line and global discounts are unsupported while allowing explicit parent row price overrides.

#### Scenario: Parent override is not classified as discount
- **WHEN** a cashier changes a bundled parent row price
- **THEN** POS SHALL treat the value as the captured row price
- **AND** POS SHALL NOT persist or allocate the difference as a discount

#### Scenario: Direct discount input does not activate bundle discounting
- **WHEN** a request supplies unsupported POS line or global discount data
- **THEN** checkout SHALL ignore or reject that unsupported data according to existing POS validation behavior
- **AND** bundle split planning SHALL not allocate a discount

## REMOVED Requirements

### Requirement: POS SHALL fallback missing component allocation price to product sale price
**Reason**: Bundle copies now persist server-derived setting-scoped informational-price snapshots, and transaction-time live fallback would make allocations drift after selection or administrative save.

**Migration**: Existing saved component snapshots remain usable. Saving a bundle copy refreshes its component snapshots, and POS thereafter uses the captured saved value including zero.

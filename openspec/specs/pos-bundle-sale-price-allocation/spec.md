# pos-bundle-sale-price-allocation Specification

## Purpose
This specification defines the authoritative pricing behavior for selected bundles in the POS cart and checkout flow, ensuring `bundle_sale_price` is used for parent rows and informational prices are used for internal revenue allocation without becoming billable.

## Requirements

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

### Requirement: POS SHALL persist immutable internal component allocations
For every fulfilled POS bundle component, the system SHALL persist the captured transaction allocation on its `SaleBundleItem` while treating that value as a nested breakdown of the authoritative owner-group Sale detail rather than additional customer revenue. The persisted value MUST originate from the POS transaction owner's captured bundle snapshot and MUST NOT be recomputed from current bundle or product prices.

#### Scenario: Cross-owner component retains captured allocation
- **WHEN** a POS owner sells a bundle for `110000` whose component has a captured allocation of `25000` and is fulfilled by another setting
- **THEN** the parent owner's Sale SHALL recognize the `85000` parent residual and the component owner's Sale SHALL recognize `25000`
- **AND** the component owner's `SaleBundleItem.price` and `SaleBundleItem.sub_total` SHALL retain the `25000` component allocation
- **AND** aggregate owner Sale revenue SHALL remain `110000`

#### Scenario: Same-owner parent and component retain nested breakdown
- **WHEN** one owner group fulfills both a bundle parent residual and one or more components
- **THEN** its Sale detail subtotal SHALL equal the parent residual plus that group's component allocations
- **AND** each fulfilled component row SHALL retain its own allocation as a nested breakdown
- **AND** the component rows SHALL NOT increase the Sale total again

#### Scenario: Multiple bundle quantities scale allocation once
- **WHEN** a POS transaction sells multiple units of a bundle
- **THEN** each component subtotal SHALL equal its captured per-bundle allocation multiplied by the sold bundle quantity
- **AND** expanded component quantity and allocation MUST NOT be expanded twice

#### Scenario: Rounding remainder preserves exact subtotal
- **WHEN** a component allocation cannot be divided evenly into a two-decimal unit price for its fulfilled quantity
- **THEN** the exact planner-allocated subtotal SHALL remain authoritative
- **AND** owner-group and checkout totals SHALL reconcile without losing or duplicating a minor unit

#### Scenario: Historical values remain immutable
- **WHEN** current bundle definitions, informational prices, or product prices change after checkout
- **THEN** the persisted component price and subtotal SHALL remain unchanged

### Requirement: Internal allocation SHALL remain non-billable to the customer
Non-zero internal `SaleBundleItem` allocation values SHALL NOT make a component a separate customer charge or independently refundable item.

#### Scenario: Customer presentation remains zero or free
- **WHEN** a completed split bundle with persisted component allocations is displayed on a receipt or POS transaction detail
- **THEN** the parent SHALL show the complete captured customer bundle price
- **AND** every component SHALL show zero or an equivalent included/free presentation
- **AND** the displayed customer total SHALL include the bundle price exactly once

#### Scenario: Component-only return remains blocked
- **WHEN** a user attempts to return a persisted bundle component without returning its parent bundle quantity
- **THEN** return eligibility or execution SHALL reject the attempt
- **AND** no Sale, payment, dispatch, inventory, return, or HPP mutation SHALL occur

#### Scenario: Whole-bundle return uses original allocation
- **WHEN** a user returns one or more whole units of a previously posted split bundle
- **THEN** the customer refund SHALL be based on the original captured parent bundle amount
- **AND** internal reversal SHALL proportionally use the persisted original parent residual and component allocations
- **AND** the full physical composition for each returned bundle unit SHALL follow its original owner and location lineage
- **AND** current product prices, bundle definitions, and average costs SHALL NOT revalue the return

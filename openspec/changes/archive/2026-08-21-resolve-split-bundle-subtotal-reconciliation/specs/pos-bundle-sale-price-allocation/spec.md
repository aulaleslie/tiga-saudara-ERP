## ADDED Requirements

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

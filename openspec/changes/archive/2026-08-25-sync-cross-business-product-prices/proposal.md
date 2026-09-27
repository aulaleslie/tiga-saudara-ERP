## Why

Cross-business price maintenance currently requires repeating the same edit for every business, even when one column should share a value. Ordinary purchase receiving also leaves `last_purchase_price` inconsistent across businesses, despite purchase costs being treated globally elsewhere and purchase imports already synchronizing that value.

## What Changes

- Add an apply-to-all control beside each modifiable value on the cross-business product price page.
- Show an input's apply-to-all control only while its numeric value differs from the value loaded for that business.
- Let the control copy the source value into the same price column for every business without saving immediately; the existing Save and Cancel workflow remains authoritative.
- When a purchase receiving note is approved, synchronize the received product's latest purchase price to every business, including creating missing business price rows without overwriting unrelated price fields.
- Add focused regression coverage for the cross-business page behavior and purchase receiving synchronization.

## Capabilities

### New Capabilities

- `cross-business-price-column-copy`: Defines conditional apply-to-all controls and unsaved same-column propagation on the cross-business product price page.
- `purchase-receiving-global-last-price`: Defines global synchronization of a product's last purchase price when ordinary purchase receiving is approved.

### Modified Capabilities

None.

## Impact

- Product cross-business price Blade view and its existing client-side masked-price behavior.
- Purchase receiving approval flow in `Modules/Purchase`.
- Product price synchronization service behavior in `Modules/Product`.
- Existing `product_prices` rows across settings; no schema migration or public API change.
- Focused Product and Purchase feature tests only; frontend interaction details may additionally require code review where the current test infrastructure cannot execute browser JavaScript.

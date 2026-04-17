## Why

Bundle-parent products currently can be routed to an existing cart line by product identity alone, so a second scan/add can append to or increment the first matching row before the cashier chooses whether this unit should use Bundle A, Bundle B, or no bundle. This breaks the expected POS behavior where bundle choice is part of the sale intent for every bundle-parent add, regardless of whether the product requires serial numbers.

## What Changes

- Require POS add/scan flows for bundle-parent products to collect bundle intent before resolving the target cart line.
- Treat selected bundle state as part of target-line identity for both serial-tracked and non-serial products.
- Allow the same parent product to coexist as separate rows for Bundle A, Bundle B, and no bundle.
- Append scanned serials only after the correct bundle-aware row has been selected or created.
- Increment quantity for non-serial products only on the row matching the selected bundle state.
- Preserve existing non-bundle product add behavior.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `pos-bundle-selection-checkout`: Bundle-parent products must ask for bundle intent before cart-line targeting for every add path, including serial and non-serial scans.
- `pos-cart-management`: Cart line add/merge behavior must target rows by product plus selected bundle state, not product alone.
- `pos-scan-input-actions`: Scan resolution for bundle-parent products must route through bundle intent capture before appending serials or incrementing quantity.

## Impact

- `Modules/Pos/Resources/views/sell.blade.php`: POS shell JavaScript scan/add flow, bundle modal handoff, and post-add line targeting.
- `Modules/Pos/Services/PosCartService.php`: Verification that existing merge keys and append behavior preserve bundle-aware line identity.
- `Modules/Pos/Tests/Feature`: Coverage for serial and non-serial bundle-parent add scenarios across selected bundle, different bundle, and no bundle rows.

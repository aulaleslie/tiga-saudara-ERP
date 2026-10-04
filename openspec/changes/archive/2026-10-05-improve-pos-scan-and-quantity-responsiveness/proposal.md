# Proposal

## Why

Rapid hardware scans can be ignored while a previous scan is being resolved or added to the cart. Quantity changes also redraw the entire cart table, making a simple action feel slower as the cart grows.

## What Changes

- Queue distinct hardware scanner and scan-helper submissions in arrival order and process them sequentially through the existing resolver and cart APIs.
- Keep camera scan duplicate suppression and selection modal behavior intact; pause queued submissions when cashier input is needed.
- After a successful quantity change, update the affected cart row and derived totals and controls from the authoritative snapshot without replacing every row.
- Fall back to a full cart render whenever the snapshot changes row identity or structure, or a targeted update cannot safely reconcile the current DOM.
- Add focused verification for rapid scans, quantity changes, approval states, and cart totals. No full-suite test run is planned.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `pos-scan-input-actions`: Preserve rapid hardware and helper scan submissions in order while the scan flow is busy.
- `pos-cart-management`: Keep quantity-edit presentation synchronized with the authoritative cart snapshot without rebuilding unaffected rows.

## Impact

- POS sell page JavaScript in `Modules/Pos/Resources/views/sell.blade.php` and its existing scan and cart rendering paths.
- Existing scan resolver, cart mutation endpoints, approval rules, stock validation, pricing, and camera decoder remain the authority; no API or schema change is expected.

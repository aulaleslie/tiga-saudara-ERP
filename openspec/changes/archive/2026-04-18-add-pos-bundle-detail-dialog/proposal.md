## Why

POS bundle rows currently show only a passive `Paket: <bundle name>` label in the cart. Cashiers need a quick way to confirm the selected bundle contents and understand the visible bundle line price without leaving or expanding the cart table.

## What Changes

- Make the `Paket: <bundle name>` label on POS cart bundle rows clickable.
- Add a read-only bundle detail dialog for the selected cart line.
- Show the parent product, bundle name, cart quantity, bundle item names, and bundle item quantities.
- Show simple price composition: base product price, bundle add-on price, final unit price, and line total.
- Keep tax and discount details out of the dialog; they remain handled by the existing cart and checkout calculations.
- Preserve the current cart table layout while the dialog is opened or closed.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `bundle-presentation`: Extend bundle detail presentation so POS cart bundle labels can open a dialog with bundle item details and simplified price composition.

## Impact

- Affects the POS sell page Blade view and POS sell modal includes.
- Uses existing POS cart snapshot fields such as `bundle_id`, `bundle_name`, `bundle_price`, `bundle_items`, `unit_price`, `qty`, and `line_total`.
- No new persistence, checkout, tax, discount, or stock behavior is required.
- No new external dependency is required.

# Proposal

## Why

When a user overrides a Purchase row's Total Baris during create or edit, the saved purchase keeps the old unit price. The row total is right, but the per-unit price on the purchase detail page (opened from the purchase list or the global payment list) is wrong. For example, a product priced Rp1.200.000 × 2 with its total overridden to Rp2.157.350 shows Rp1.200.000 instead of Rp1.078.675. The canonical unit cost that returns, settlements, inventory replay and reports read is also saved with the old price.

## What Changes

- When a Purchase row's total is overridden, the unit price the user effectively entered (before any discount) is updated together with the row's unit price and total. Saving the purchase then stores the derived price, not the earlier one.
- The canonical (base-unit) price is still computed only on the server, from the entered price and the conversion factor. The committed row total stays authoritative, and differences below Rp0,01 per unit from 2-decimal rounding are accepted.
- Only new saves are affected. Existing purchase details are not backfilled. An old row is corrected only when a user edits it and overrides its total again.
- Out of scope: the sales cart, which keeps its own rounding behaviour, and any change to purchase return or settlement code. Those read the saved detail price, so they are correct for new transactions without changes.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `purchase-manual-line-total-authority`: adds a requirement that a saved Purchase row with an overridden total keeps the entered and canonical unit price derived from that total.

## Impact

- `app/Livewire/Purchase/ProductCart.php` (`updateLineTotal`): the cart row option for the entered price.
- Saved data: `purchase_details.entered_unit_price`, `unit_price` and `price` for rows with `pricing_source = manual_line_total`, for new saves only.
- Tests: Purchase create and edit feature tests with a total override, including one with a discount.
- No schema, API or dependency changes.

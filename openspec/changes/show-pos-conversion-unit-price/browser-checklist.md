# Human Browser Checklist: POS Conversion Unit Price

Manual verification for the unit-selection modal in `Modules/Pos/Resources/views/sell.blade.php`. Not automated — run in a real browser against a POS session.

## Setup
- A product with a base unit and at least two sales-enabled conversions:
  - Conversion A: has a `ProductUnitConversionPrice` row for the acting business with a numeric price.
  - Conversion B: sales-enabled but no `ProductUnitConversionPrice` row (or a row with `price = null`) for the acting business.
- Optionally a Conversion C that is disabled for sales in the acting business.

## Checks

1. **Configured conversion price**
   - Search or scan the product's base barcode to open the unit picker.
   - Confirm Conversion A's card shows `Harga konversi: Rp<amount> / <Unit>` using the same currency formatting as the base-unit price.
   - Confirm the base-unit card is unaffected (still shows `Harga: Rp<amount>`).

2. **Missing conversion price**
   - Confirm Conversion B's card shows `Harga konversi belum tersedia` — not `Rp0`, not blank, not a synthesized factor × base-price value.
   - Confirm Conversion B is still selectable and can be added to the cart (missing price only affects display, not eligibility).

3. **Disabled conversion**
   - Confirm Conversion C still shows the "Nonaktif" badge and disabled styling as before, unaffected by the price-display change.

4. **Final cart total still authoritative**
   - Select Conversion A and complete the add-to-cart flow.
   - Confirm the cart line's actual charged price follows existing pricing rules (customer tier, packing, bundle, tax, rounding, overrides) and is not simply the conversion reference price shown in the picker — verify this explicitly if any active rule (e.g. a customer-tier price) would produce a different amount than the picker's reference price.

5. **Regression spot-check**
   - Confirm repeated scans/selections still prompt the picker each time (no caching of previous unit choice).
   - Confirm bundle-parent products still proceed to the bundle-selection modal after a unit is chosen, unaffected by the price display change.

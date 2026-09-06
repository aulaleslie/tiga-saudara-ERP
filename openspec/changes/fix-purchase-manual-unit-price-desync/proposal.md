## Why

Editing a Purchase cart row's unit price (via "Harga Satuan" / `ProductCart::updatePrice()`) persists a `sub_total` that reflects the newly entered price, but leaves `unit_price`/`price` stuck at the previous value. The purchase detail page then displays a per-unit price that never matches what was actually charged, even though the row total and document total are correct. A database scan found 41 affected `purchase_details` rows across 29 purchases (drafted and already-received) since the manual-unit-price pricing source was introduced, with the stale unit price differing from what `sub_total` implies by as much as several hundred thousand rupiah per row.

## What Changes

- Fix `ProductCart::updatePrice()` (and any sibling cart mutators found to share the pattern — `updateUnit()`, `updateTax()`, `updateQuantity()`) so the cart's `options.unit_price` is refreshed alongside `price`/`unit_price`/`entered_unit_price` on every manual price edit, instead of being carried forward stale via `array_merge()`.
- Fix `PurchaseNormalizer::normalizeDetail()` so the canonical unit price is derived from the cart's authoritative top-level `price`/`entered_unit_price`, not from the possibly-stale `options.unit_price` cache key.
- Add a one-off backfill (Artisan command, run manually, not part of the deploy pipeline) that recomputes `unit_price`/`price` on the 41 known-affected `purchase_details` rows from their already-correct `sub_total`, `product_discount_amount`, `product_tax_amount`, `quantity`, and the parent purchase's `is_tax_included` flag — a display-only correction. `sub_total`, `total_amount`, payments, stock, and average/last purchase price are explicitly out of scope and must not be touched.

## Capabilities

### New Capabilities
- `purchase-manual-unit-price-consistency`: Purchase cart rows keep `unit_price`/`price` consistent with the user's last committed unit-price edit and with the row's persisted `sub_total`, across cart mutation, save, and reload.

### Modified Capabilities
(none — no existing spec covers manual unit-price consistency; `purchase-manual-line-total-authority` covers the sibling "Total Baris" entry path and is unaffected)

## Impact

- `app/Livewire/Purchase/ProductCart.php` (`updatePrice()`, and possibly `updateUnit()`, `updateTax()`, `updateQuantity()`)
- `Modules/Purchase/Services/PurchaseNormalizer.php` (`normalizeDetail()` unit-price resolution order)
- New one-off Artisan command for the 41-row backfill (script only, run once against production data; not scheduled, not part of CI)
- No schema changes. No changes to payments, stock, receiving, or product costing (`average_purchase_price`/`last_purchase_price` are intentionally left as-is per explicit scope decision).

# Purchase Conversion Units

## Overview

Purchase lines can be entered in a product's base (smallest) unit or in an eligible configured conversion unit (e.g. `2 BOX` instead of `24 PCS`). The entered unit, quantity, unit price, discount, and conversion factor are preserved as an immutable historical snapshot on `purchase_details`, while all operational, inventory, costing, and reporting logic continues to consume the canonical base-unit quantity and price. This document describes the invariants and behavior an operator or developer needs to reason about this feature; it does not restate the code.

## The base-unit invariant

- Every product has a canonical `base_unit_id`, its smallest counting unit.
- A conversion is defined as `1 selected unit = conversion_factor × base unit`.
- **A conversion factor must be strictly greater than `1`.** A factor of `1` or less does not represent a real conversion and cannot be created, edited to, or newly selected on a Purchase line — the base unit itself is always used for a 1:1 relationship.
- **Serialized products additionally require a whole-number conversion factor.** One serial number always represents exactly one base unit, so a fractional factor could produce a non-whole serial count and is rejected.
- These rules are enforced server-side wherever a conversion is created or edited (full product edit, unit-configuration Livewire flows, product quick-add, and any import/request path that writes a conversion), regardless of client input.
- Existing conversion rows with a factor `≤ 1` are **not rewritten or deleted**. They remain interpretable for historical documents that already reference them, but they are excluded from selection on any *new* Purchase line or edit. Correcting such a row's underlying unit configuration is a separate, deliberately out-of-scope rebase operation.

## Entering a Purchase line in a conversion unit

- Each Purchase create/edit line lets the user pick the product's base unit or any of its currently eligible conversions. The default selection is the base unit.
- Only units that are currently active, share the product's base unit, and have a factor `> 1` (whole, if the product is serialized) are offered.
- Entering `2 BOX` at `Rp12,000/BOX` (factor 12) produces:
  - Canonical `quantity` = `24` (PCS), `unit_price` = `Rp1,000`/PCS — used by every downstream operational and financial calculation.
  - A snapshot of the entered values: `purchase_unit_id`, `product_unit_conversion_id`, `entered_quantity` (`2`), `entered_unit_price` (`Rp12,000`), `entered_product_discount_amount`, `conversion_factor` (`12`), `unit_name` (`BOX`), `base_unit_name` (`PCS`).
- Decimal quantities are supported (up to 3 decimal places for both the entered and the resulting canonical quantity); an entry that would produce unsupported canonical precision is rejected rather than silently rounded.
- Two lines for the same product in different units (e.g. `2 BOX` and `3 PCS`) are kept as separate cart rows — cart/document identity is product **and** selected unit, not product alone. Adding the same product in the same unit again increments that existing row.
- All conversion identity submitted by the client is re-validated server-side against the product's currently loaded conversions before it is trusted; a client-supplied factor or canonical value is never accepted directly.

## Historical fallback (legacy rows and stale conversions)

- Purchase lines created before this feature (or via import) have every conversion snapshot column `null`. They are treated as an implicit base-unit line with a factor of `1` wherever they are displayed, edited, duplicated, or calculated — no data backfill is required or performed.
- A Purchase-detail snapshot, once persisted, is authoritative for that line. If the referenced conversion is later deactivated, its factor changed, or the row deleted entirely, the **stored snapshot** (not the live conversion configuration) still governs how that already-persisted line is interpreted — historical documents never silently reinterpret their own numbers because someone edited product configuration afterward.
- **Duplication** ("Duplikat" on a Purchase) is the one place a stale conversion is actively re-evaluated, because duplicating creates a brand-new line, not a continuation of history: the source line's conversion is carried over only if it is *still* currently eligible, its unit and base-unit identity still match, **and** its live factor still matches the factor recorded in the source snapshot. If any of those checks fail (conversion deleted, unit deactivated, base unit changed, or the factor edited in place), the duplicated line falls back to a clean base-unit row built from the source's canonical quantity/price/discount — it never resurrects an unavailable or reinterpreted conversion.
- **Import** never populates any conversion snapshot field. An imported Purchase line is always a base-unit, factor-one row by construction; the imported "satuan" (unit) text only ever affects the *product's* base unit at creation time, never a per-line conversion.

## Receiving and serials

- Receiving defaults each line to its Purchase-snapshotted ordered unit and shows the canonical (base-unit) equivalent and remaining quantity alongside it. A receiver may receive in the ordered unit or the base unit; unrelated conversions for that product are not offered.
- Whatever unit is used for receiving, the submitted amount is converted to canonical base units before being persisted on `received_note_details.quantity_received` (a decimal column). Minimum-quantity, remaining-quantity, completion, and over-receiving checks — including the locked-approval recheck — all compare canonical, decimal-safe quantities, not the entered unit.
- For a serialized product, the canonical received quantity for a receipt line must be a whole number, and the number of submitted serials must exactly equal that canonical quantity. One serial is always one base unit; a fractional entered-unit quantity is only accepted if multiplying by the factor produces a whole canonical quantity.

## Purchase return semantics

- Return eligibility, remaining-quantity checks, and valuation always operate on the canonical base-unit quantity, exactly like receiving — a return is checked against how much has actually been *received* in base units, never against the entered conversion-unit count.
- A partial return that evenly divides a conversion-sourced line's factor rebases the entered-unit snapshot to the new canonical remainder (e.g. returning `12` of `24` canonical PCS from a `2 BOX`/factor-12 line rebases the entered snapshot to `1 BOX`). A partial return that does **not** divide evenly invalidates the entered-unit snapshot for the remaining quantity, which then displays and calculates from its canonical base-unit values instead of a now-meaningless partial-unit label.
- Returning the exact full canonical quantity of a line is always permitted regardless of unit; returning beyond what has been received is always rejected, whether the source line was entered in the base unit or a conversion unit.

## Purchase-only rounding exclusion

- Purchase no longer reads or applies the business's configurable automatic row-total rounding increment (`row_total_rounding_increment`). Automatically calculated Purchase rows retain their exact, ordinary currency-precision (2-decimal) total — no configured increment is layered on top.
- This exclusion is scoped to Purchase calculation paths only. **Sales and POS are unaffected** and continue to honor the business's configured rounding increment exactly as before; the shared rounding setting itself was not changed or removed.
- Existing manual unit-price overrides, manual line-total overrides, discounts, taxes, and shipping calculations are unaffected by the rounding removal — only the automatic increment step is gone from Purchase.
- Loading an already-persisted Purchase document does not recalculate or re-round it; the stored total is preserved as-is until a subsequent price-affecting interaction re-runs the (now increment-free) Purchase calculation.

## Where to look in code

- Conversion validation and eligibility: `Modules/Product/Entities/Product::eligiblePurchaseConversions()`, `Modules\Product\Support\ProductCreateValidation`.
- Decimal-safe conversion arithmetic: `Modules\Purchase\Services\PurchaseUomConversionService`.
- Cart/persistence normalization: `Modules\Purchase\Services\PurchaseNormalizer`.
- Duplication fallback logic: `App\Livewire\Purchase\CreateForm::resolveDuplicateUnitIntent()`.
- Receiving quantity/serial rules: `Modules\Purchase\Services\PurchaseReceivingQuantityService`.
- Rounding exclusion: `App\Support\RowTotalRoundingCalculator` (consumed differently by Purchase vs. Sale/POS call sites).

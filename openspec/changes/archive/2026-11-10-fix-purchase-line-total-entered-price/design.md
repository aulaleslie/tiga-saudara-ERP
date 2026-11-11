# Design

## Context

`updateLineTotal` in the Purchase cart works back from a committed row total to a base unit price (before the discount) and writes it to the row's `price`, `unit_price` and `options.unit_price`. It does not write `options.entered_unit_price`. When the purchase is saved, `PurchaseNormalizer` resolves the entered price with `entered_unit_price` taking precedence over `unit_price`. `PurchaseUomConversionService::convert` then computes the canonical price as entered price ÷ conversion factor. Because `entered_unit_price` is stale, both stored prices keep the old value. `sub_total` is not affected, because the manual total is saved as authoritative (`RECALC_FLAG = false`).

Every other cart pricing handler (unit price edit, unit/conversion change, discount change, and so on) already writes `options.entered_unit_price`.

## Goals / Non-Goals

**Goals:**
- Make the line-total override write the entered price the way every other pricing handler does.

**Non-Goals:**
- Computing the canonical price from the row total at 6 decimals.
- Backfilling rows that were saved with the stale price.
- Any change to the normalizer, the conversion service, returns, settlements or the sales cart.

## Decisions

**D1 – Fix in the cart, not the normalizer.** Write `options.entered_unit_price` in `updateLineTotal` from the same rounded base price it already writes to `unit_price`. Reordering the normalizer's precedence (to prefer `unit_price`) was rejected: it would change how every other pricing source is resolved, and with a conversion factor other than 1, `unit_price` in the cart is not always the entered-unit price.

**D2 – Keep the canonical price server-derived (option a).** The normalizer deliberately refuses to let a row total sent by the client set the 6-decimal inventory cost. Computing the canonical price from the total (option b) would go against that guard and need a trusted path for the total. The error that remains is less than Rp0,005 per unit and only happens when the total does not divide evenly by the quantity. `sub_total` stays exact in either option.

**D3 – Edit flow needs no extra code.** `PurchaseUomConversionService::convert` only reuses the stored canonical price when the newly entered price equals the stored entered price. Once the override writes a new entered price, the canonical price is recomputed. This also corrects a stale row whenever a user overrides its total again.

## Risks / Trade-offs

- [Rows saved before the fix keep the stale prices] → The user accepted this; only transactions from now on matter.
- [With a conversion factor other than 1, the base price is in the selected purchase unit] → `updateLineTotal` already works per cart unit, so the entered price is in that same unit. A test with a conversion unit should confirm this if one is cheap to set up.
- [Sub-rupiah drift for totals that don't divide evenly] → Accepted (D2). The total stays authoritative.

## Migration Plan

No migration. Deploy the code change; rollback is a revert.

## Why

POS conversion (unit) pricing is broken: when a product with a unit conversion (e.g. base unit REAM, box conversion of 5 REAM) is in the cart, the line price is captured once at add time and never recalculated on quantity change, so the total is wrong as soon as the cashier adjusts the quantity. Conversion lines also ignore the customer tier, so a reseller can be charged the non-tier box price. Cashiers additionally have no visibility into how a quantity is packed into boxes vs loose base units or why a given price was chosen.

## What Changes

- Track quantity **always in base units**; scanning a conversion (box) barcode adds `conversion_factor` base units and records the conversion as a packing hint on the line.
- Introduce a **packing pricing engine**: for each full group of `factor` base units, charge the cheaper of the box price vs `factor × tier base-unit price`; the remainder is priced as loose base units at the tier price. The cheaper total always wins so the customer tier is respected.
- **Reprice on every quantity change and customer-tier change** — fixing the core bug where price stayed frozen.
- Store one **blended line** (`unit_price = line_total / qty`, display-only) with an **authoritative `line_total`** so totals tie out to the rupiah despite blending.
- **Performance:** capture all pricing inputs (`pricing_basis`: factor, box price, base price, tier prices, tax) **once at add/scan time**; quantity and customer-tier updates re-pack using cached inputs with **zero additional DB queries**. Prices are **frozen at scan time** (no mid-cart refresh).
- Packing applies to **any line whose product has a box conversion**, regardless of whether entry was via box barcode or product search.
- Add a **read-only breakdown panel** in the sell UI showing the packing split (e.g. "1 box + 1 ream"), both price ways compared with the winner marked, and a customer-tier badge. Nice-to-have: a tier badge on customer selection.
- Extend the **receipt** unit breakdown to express the packing split.

## Capabilities

### New Capabilities
- `pos-conversion-packing-pricing`: base-unit quantity tracking, per-group cheapest-of packing pricing (box vs tier base units), blended-line storage with authoritative line total, cached `pricing_basis` for zero-DB re-pricing, and reprice triggers on quantity/customer change.

### Modified Capabilities
- `pos-cart-management`: cart add/update/customer-selection route lines with a conversion through the packing engine and re-pack on quantity change; merge key excludes the blended price so repeated box scans coalesce and re-pack.
- `pos-receipt`: line unit breakdown expresses the packing split (boxes + loose base units).

## Impact

- **Code**: `Modules/Pos/Services/PosCartService.php` (add/update/customer reprice, merge key), new `Modules/Pos/Services/PackedLinePricingService.php`, `Modules/Pos/Services/PosCartTotalsCalculator.php` (authoritative line-total override), `Modules/Pos/Services/PosTransactionSnapshotMapper.php` (merge-key parity), `Modules/Pos/Services/PosReceiptService.php` + `receipt.blade.php`, `Modules/Pos/Resources/views/sell.blade.php` (breakdown panel).
- **Data**: no schema change (box tier price is derived; no new columns on `product_unit_conversion_prices`). Cart session lines carry a new `pricing_basis` structure.
- **Behavior**: conversion lines are no longer a distinct frozen-price line type — base and conversion pricing merge into one packed path (bundles unchanged). Prices are frozen at scan time.

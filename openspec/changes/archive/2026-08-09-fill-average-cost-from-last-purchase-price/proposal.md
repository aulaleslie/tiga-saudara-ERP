# Fill Average Cost From Last Purchase Price

## Why

Some `product_prices` rows carry `average_purchase_price` of zero or null. Every report that values inventory at average cost — inventory summary, inventory valuation, warehouse stock valuation, and the general ledger paths that read them — treats those rows as costless, which overstates margin and understates stock value.

Two recovery commands already exist and neither closes this gap:

- `product:normalize-purchase-prices` derives cost from historical *received* purchases, so it cannot help a product that was never received in this system.
- `product:seed-average-cost-from-sales-hpp` derives cost from imported sales HPP snapshots, so it cannot help a product that was never sold.

A product that was neither received nor sold under an owner still has a stated purchase price on its price row: `last_purchase_price`. That figure is a net unit cost — [`ProductCart`](../../app/Livewire/Purchase/ProductCart.php) and `NormalizeProductPurchasePricesCommand` both treat it as a unit cost held separately from tax fields — so it is a legitimate, if approximate, cost of record. This change adds the last rung of the recovery ladder: when no purchase history and no sales history can supply a cost, fall back to the stated purchase price.

## What Changes

- **New command** `product:fill-average-cost-from-last-purchase-price`, dry-run by default with a `--write` flag, matching the convention of both sibling commands.
- **Same-owner fill**: for a `product_prices` row whose `average_purchase_price` is zero or null, set it from that same row's `last_purchase_price` when that value is positive.
- **Cross-owner fallback**: when the row's own `last_purchase_price` is also zero or null, select a donor row for the same product from another setting and use the donor's `last_purchase_price` for both fields.
- **Cross-owner fallback also backfills `last_purchase_price`** on the target row, so the row stops being a repeat candidate on later runs and the two fields stay coherent with each other.
- **Donor priority reuses the existing owner ladder** — Perdana, then Top IT, then Tiga Nusa — the same ordering `product:seed-average-cost-from-sales-hpp` uses to pick a shared baseline, so both commands tell the same story about which owner is the canonical cost source. Owners outside those three buckets rank last and break ties by ascending `setting_id`, because the ladder itself does not rank them and the command must be reproducible.
- **Null is treated as zero.** Both mean "no cost known". This matches the shipped behavior of the sibling command, which already compares `(float) $price->average_purchase_price`.
- **Existing rows only.** The command never creates a `product_prices` row, even where one is missing for a setting.
- **Never overwrites a positive average.** There is no force mode.

## Capabilities

### New Capabilities

- `product-last-purchase-cost-fill`: Terminal fallback that fills a zero or null `average_purchase_price` from a stated `last_purchase_price`, preferring the row's own value and otherwise borrowing from a donor owner under a fixed priority ladder, backfilling the donor cost into the target row's `last_purchase_price` at the same time.

## Impact

- **New**: `Modules/Product/Console/FillAverageCostFromLastPurchasePriceCommand.php`, registered in the Product module's console provider alongside its siblings.
- **Reads**: `product_prices`, `settings`.
- **Writes**: `product_prices.average_purchase_price`, and `product_prices.last_purchase_price` only on the cross-owner path. Only rows whose `average_purchase_price` is currently zero or null are touched.
- **Downstream**: inventory summary, inventory valuation, and warehouse stock valuation reports read `average_purchase_price` and will report higher stock values for affected products. No currently positive average changes.

### Accepted exception to owner scoping

The cross-owner fallback deliberately writes one owner's cost onto another owner's price row. Recent work moved the opposite direction — commit `8575d86e` restricted price snapshot updates to the marker owner, and `d7d6c838` restricted price and stock snapshot imports to resolved owner settings — so this is a knowing exception, not an oversight.

The rationale: those commits govern *import* paths, where the source data carries its own owner attribution and honoring it is the whole point. This is a *repair* path over rows that have no owner-attributed cost at all. For inventory valuation, a stale cross-owner cost is materially better than a zero, which reports as free stock. The exception is confined to rows already at zero and never displaces an owner's own figure.

The cost written this way is an approximation borrowed from another business. It is not a substitute for a real purchase, and a later run of `product:normalize-purchase-prices` will correctly supersede it once genuine receiving history exists.

## Open Questions

- **Resolved**: The recovery rate of the cross-owner path is unmeasured. The command's dry-run mode is fully implemented and provides the four counters (`Considered rows`, `Filled (own last_purchase_price)`, `Filled (donor owner)`, `Unchanged (positive average)`, `Unresolved (no donor)`). Running `php artisan product:fill-average-cost-from-last-purchase-price` against staging/production will size the actual recovery rate.

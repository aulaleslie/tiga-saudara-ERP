# Design

## Context

`average_purchase_price` lives on `product_prices`, one row per `(product_id, setting_id)`, alongside `last_purchase_price`. Both are `decimal(10,2)` and nullable, cast to `decimal:2` on the model.

Two commands already fill `average_purchase_price` from richer sources. This one is the terminal fallback beneath both:

```
product:normalize-purchase-prices          ← received purchase history (best)
product:seed-average-cost-from-sales-hpp   ← imported sales HPP snapshots
product:fill-average-cost-from-...         ← stated last_purchase_price (this change, weakest)
```

Ordering matters operationally: this command should run last, because it writes an approximation that the stronger sources would otherwise supersede. It does not enforce that ordering — it only declines to overwrite a positive average, which is sufficient, since a stronger source having run already leaves a positive value behind.

## Resolution flow

For each `product_prices` row:

```
average_purchase_price > 0 ?
  yes ──▶ unchanged
  no  ──▶ own last_purchase_price > 0 ?
            yes ──▶ average = own last                      [own_fill]
                    (last_purchase_price untouched)
            no  ──▶ donor row for same product,
                    other setting, last > 0 ?
                      yes ──▶ average = donor.last          [donor_fill]
                              last    = donor.last
                      no  ──▶ unresolved
```

Four terminal outcomes, each a reported counter: `own_fill`, `donor_fill`, `unchanged`, `unresolved`.

## Decisions

### Null and zero are the same condition

Both mean no cost is known. Casting through `(float)` collapses them, which is what `SeedAverageCostFromSalesHppCommand::updateExistingPrice` already does with `(float) $price->average_purchase_price`. No special-casing, and the behavior stays consistent with the shipped sibling.

### Donor priority reuses the existing ladder

`SeedAverageCostFromSalesHppCommand::resolveBaselineFromCandidates` picks a shared baseline in the order Perdana → Top IT → Tiga Nusa, resolving setting IDs by lowercased `company_name` match against `perdana`, `cv top it internusa`, and `cv tiga nusa computer`.

Reusing that ordering means both commands answer "which owner is the canonical cost source" identically. The alternative — freshest `updated_at` — is arguably more accurate per row but would establish a second, conflicting convention, and `updated_at` on a price row moves for reasons unrelated to cost (a sale price edit touches it too), so it is a poor proxy for cost recency.

The existing helper `getBucketForSetting` returns `null` for owners outside the three buckets. That is fine for the sibling, which only ever consults the three, but this command may find its only donor at an unranked owner. Unranked donors therefore rank after all three, with ascending `setting_id` as the final tiebreak so runs are reproducible.

Extracting the bucket resolution into shared code is tempting but out of scope; duplicating the small lookup keeps this change additive and avoids touching a command that is already in production use. If a third consumer appears, extract then.

### The cross-owner path writes two fields

Writing only `average_purchase_price` would leave the row with a positive average and a zero `last_purchase_price` — an incoherent pair, and one that keeps the row looking like a candidate for any future tool that reasons about missing purchase prices. Writing both makes the row internally consistent and terminal.

This is the deliberate owner-scoping exception recorded in the proposal. It is bounded: only rows already at zero, never displacing an owner's own figure, never modifying the donor.

### No row creation

`SeedAverageCostFromSalesHppCommand` creates missing rows from a template. This command does not. Creating rows across every setting for every product multiplies the blast radius well beyond a cost repair, and the user confirmed the need is real but not now. Declining to create keeps the change reversible by inspection: every write lands on a row that already existed.

### Pre-run snapshot invariant

To ensure donor selection is deterministic and independent of chunk boundaries, donor candidates must be resolved purely from pre-run state. Any values written during the command's execution must never become candidates for other rows in later chunks. This invariant is structurally enforced by building a plain-array snapshot of all positive `last_purchase_price` rows before the `chunkById` loop begins, rather than reloading siblings dynamically from the database during the pass.

### `stock_managed` is not a filter

Both siblings scope to `Product::where('stock_managed', true)`. This command iterates `product_prices` directly rather than products, because the unit of repair is the price row. A non-stock-managed product with a stated purchase price and a zero average is still misreported by any valuation path that reads it, and filling it costs nothing. If it emerges that non-stock-managed products should be excluded, that is a one-line join, but there is no evidence for it now.

## Risks

**The recovery rate is unknown.** If zero-average rows overwhelmingly also have zero `last_purchase_price` at every owner, the command resolves little. Dry-run mode measures this directly on first run, so the risk is cheap to retire and does not block the design. The `unresolved` counter is the number that answers it.

**Borrowed costs are approximations.** A Tiga Nusa purchase price applied to a Perdana row is not that business's cost. It is better than zero for valuation, and it is superseded the moment real receiving history exists, but reports built on donor-filled rows carry that caveat. The counters distinguish `own_fill` from `donor_fill` precisely so the proportion of borrowed costs is visible after every run.

**Silent supersession ordering.** An operator who runs this command before `normalize-purchase-prices` leaves approximations in place that the stronger command would then decline to overwrite, since it too preserves positive averages. Mitigated by documenting the intended ordering here rather than by enforcement, which would require this command to know about the others.

## Implementation notes

Chunk over `ProductPrice` with `chunkById(100, ...)`, matching the siblings' memory profile. Donor lookup needs all price rows for a product, so group by `product_id` — either preload the sibling rows per chunk in one query keyed by `product_id`, or iterate products and fetch their rows together. The latter reads more naturally and mirrors `processProduct` in the sibling, at the cost of a products join; either is acceptable at this data size, and the per-chunk preload avoids an N+1 if the row count is large.

Writes go through `$price->update([...])` inside the `--write` branch only, with counters incremented in both modes so dry-run and write report identically.

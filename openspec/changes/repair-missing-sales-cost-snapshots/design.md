## Context

`sale_details` carries four snapshot columns — `cost_unit_snapshot`, `cost_total_snapshot`, `cost_snapshot_source`, `cost_snapshot_at` — added by `2026_06_26_000102_add_cost_snapshot_columns_to_sale_details_table`. There is no history table and `BackfillSalesCostSnapshotsCommand::flushUpdates()` issues a bare `DB::table()->update()`, so prior values from earlier backfill runs are already unrecoverable.

Three producers write these columns today:

- `SalesCostSnapshotService` at sale-post time, using `averagePurchasePrice(setting_id)`; on a miss it writes cost 0 with source `MISSING_AVERAGE_PRICE`.
- `sales:backfill-cost-snapshots`, replaying purchase history per product via `HistoricalReplayEngine::replayWithBucketIsolation()`; on total miss it writes cost 0 with source `BACKFILL_ZERO_FALLBACK`.
- The sales HPP snapshot import, writing `HPP_SNAPSHOT_IMPORT`, treated as authoritative by every other writer.

The zero rows are stuck. `BackfillSalesCostSnapshotsCommand.php:177-178` skips any source with a `BACKFILL_` prefix unless `--force`, so `BACKFILL_ZERO_FALLBACK` reads as already-handled on every subsequent run, while `--force` would recompute good `BACKFILL_RUNNING_AVERAGE` rows at the same time. There is no path that revisits only the zeros.

Note that the shipped `sales-cost-snapshots` spec states backfill "SHALL fill only sale details whose cost snapshots are null." The implementation keys on source prefix, not null cost. Spec and code already disagree, and the disagreement is precisely the zero-row trap.

Constraint that shapes everything below: this data is on production with no cleanup window. Measurement must precede correction, and correction must not be able to damage a value anyone has already reported on.

## Goals / Non-Goals

**Goals:**

- Measure the gap by observable fact, so no row can hide behind a source label that claims it was handled.
- Separate rows that a purchase-derived cost can resolve from rows that it cannot, before deciding how much repair is worth building.
- Fill zero-cost rows from the nearest eligible purchase, preferring cost that was current when the item was sold.
- Make every repaired row traceable to the purchase it was anchored on and the time distance involved.
- Confine writes to a risk class where the worst outcome is replacing an obviously-broken zero with a plausible number.

**Non-Goals:**

- Rewriting any snapshot that currently holds a positive cost. No force mode exists on the repair path.
- Fixing the two known defects in `sales:backfill-cost-snapshots` — the source-prefix skip predicate and the per-product `$fallbackDate` computed once from the earliest sale at lines 134-148 then applied to every uncovered detail of that product. Both are real and the second likely produces large distortion, but correcting them rewrites existing non-zero costs, which is a different risk class and needs its own decision.
- Adding a snapshot revision/history table. Considered and set aside — see Decisions.
- Changing `SalesCostSnapshotService` write-time behavior, even if the audit shows it is still producing zeros on recent sales. That finding is an output of this change, not a task in it.
- Repairing `sale_bundle_items`. That table has no cost columns; cost lives on the parent `sale_detail`.

## Decisions

### Missing is defined by cost, not by source

`cost_unit_snapshot <= 0 OR IS NULL` is the definition, in both the audit and the repair filter.

Source is a claim about what a previous run attempted; cost is the fact about what a row now holds. `BACKFILL_ZERO_FALLBACK`, `MISSING_AVERAGE_PRICE`, and `CURRENT_AVERAGE_PRICE`-with-zero all assert handling while leaving a row that reports 100% margin. Filtering on source would hide exactly the population being measured.

Alternative considered: filter on source, treating the `BACKFILL_*` prefix as authoritative. Rejected — it reproduces the trap that created the problem.

Consequence accepted: legitimately-zero service lines are swept in. They are separated by the `products.stock_managed` fact rather than by source label, keeping the principle consistent, and reported as their own partition rather than silently excluded.

### The repair reads source for one narrow purpose

The audit ignores `cost_snapshot_source` entirely as a filter. The repair reads it only to skip rows already adjudicated as unrepairable, so a growing permanent set of no-purchase-history rows does not get re-examined on every run.

This is a deliberate, stated exception to the previous decision rather than a contradiction of it, and it is what makes repeated runs cheap.

### Unrepairable rows are labeled, not left blank

A product with no eligible purchase history keeps cost 0 — there is no honest number to write — but receives a distinct terminal source label.

The cost write is a no-op; the label is the point. It distinguishes "we looked and there is nothing to find" from "nobody has processed this yet," retires the row from future audits so the gap number reflects real remaining work, and preserves that distinction for any later HPP import or manual costing.

### Nearest single purchase, used directly as the cost

The anchor purchase's landed cost becomes the snapshot. It is not used as a seed for a running-average replay.

These rows are zero precisely because `replayWithBucketIsolation` already walked the timeline and produced nothing. Rerunning average machinery against the same data returns the same nothing. A single purchase's landed cost definitionally exists whenever any eligible purchase exists, and yields a traceable claim: costed from purchase detail N, dated D days from the sale.

Alternative considered: `product_prices.last_purchase_price`. Rejected — it is a current denormalized scalar with no date, so a 2024 sale would be costed at 2026 prices. That magnitude error is the original motivation for this change.

### Prefer past unconditionally, then future, then cross-bucket

Resolution order for a sale detail on product P, setting bucket B, date D:

1. Nearest eligible purchase of P in bucket B with effective date `<= D`.
2. Nearest eligible purchase of P in bucket B with effective date `> D`.
3. Same cascade across buckets.
4. Leave at zero.

Past wins even when a future purchase is closer in absolute days. This never prices a sale with information that did not exist at sale time, and it never produces the sold-in-March-costed-from-July artifact. It is also a strict improvement on `buildFallbackAverages`, which only ever queries `date > $afterDate` and therefore can pass over an earlier purchase sitting directly before the sale.

Alternative considered: purely nearest by absolute distance. More accurate when the prior purchase is ancient, but reintroduces forward-costing. A hybrid with a ratio threshold was considered and rejected as a knob that would be hard to justify later. `--max-distance-days` is the safety valve instead.

Each rung writes a distinct source label so cross-owner and forward-costed repairs stay identifiable.

### Eligibility predicate is shared between audit and repair

The audit's "has this product ever been purchased?" check and the repair's "find the nearest purchase" query must use one extracted predicate — purchase status in `RECEIVED`, `RECEIVED_PARTIALLY`, `COMPLETED`, with `RECEIVED_PARTIALLY` excluded when it has no approved receipt, matching `HistoricalReplayEngine::buildFallbackAverages`.

If the two drift, the audit reports rows as repairable that the repair then skips, and the numbers stop meaning anything.

### Landed-cost calculation is extracted, not reimplemented

`buildFallbackAverages` at `HistoricalReplayEngine.php:357-420` already computes receipt-aware landed cost — preferring approved received-note details, prorating via `calculateLineReceiptCost`, falling back to `calculatePurchaseDpp` for `COMPLETED` documents only, and skipping `RECEIVED_PARTIALLY` without receipts. That per-detail logic is separable from the forward-only scan wrapped around it and must be extracted so one `PurchaseDetail` can be priced. Reimplementing it would fork tax and discount handling.

### No revision table

Rejected for this scope. The repair writes only where cost is currently zero and never touches a positive value, so the pre-state of every written row is known to be zero. A distinct source label per rung makes any run reversible by setting cost back to zero for that label.

A revision table would be the right call for the deferred backfill fixes, which do rewrite existing non-zero values. It is unnecessary overhead here.

### Repair evidence is recorded

Each repaired row records the anchor purchase detail id and the signed distance in days between the purchase and the sale. `--max-distance-days=N` refuses to anchor beyond the threshold, falling through to zero.

On production, leaving a zero is a better failure than writing a confidently stale cost. The threshold should be chosen from the audit's distance histogram rather than picked up front.

## Risks / Trade-offs

**Repaired costs are approximations, not reconstructed history** → Every repaired row is labeled by rung and carries its evidence distance, so any row can be found and revisited. Rows anchored on distant or forward purchases are identifiable as a class rather than blended into the general population.

**Filling zeros changes reported margins** → This is the intended effect, and the direction is favorable: a zero cost currently reports 100% margin, which is unambiguously wrong. No currently non-zero value changes, so no already-reported figure moves. Deploy the audit first so the size of the shift is known before it happens.

**Bundle parent products likely have no purchase history** → Expected to surface as a large no-purchase-history partition. They are labeled terminal and left at zero, which is correct for this change; whether bundle parent cost should be derived from component costs is a separate question this change does not answer.

**Audit and repair predicates drifting apart** → Mitigated by extracting one eligibility predicate used by both. Worth a test asserting that a row the audit counts as repairable is one the repair will actually anchor.

**`stock_managed = false` population is unknown** → Could not be checked during authoring; no database access. Treated as a reported audit dimension rather than an assumption, so the first audit run answers it. If the population is empty the branch is inert; if it is not, those rows are correctly partitioned rather than counted as a permanent unfixable residue.

**Cross-bucket repairs cross owner boundaries** → The bucket isolation in the existing replay exists for a reason. Cross-bucket is the last rung before giving up, and is separately labeled so its blast radius is measurable and revertible on its own.

**Quantity unit mismatch** → `cost_total_snapshot` is `unit_cost × quantity`. Both `sale_details.quantity` and the purchase-derived landed cost must be per base unit; if either side is in user units on some rows, totals are wrong by the conversion factor, silently and only on converted products. Verify against the qty/base_qty contract before write mode ships.

**Sale status scope** → The replay engine counts only `Completed`, `DISPATCHED`, `RETURNED PARTIALLY`, `RETURNED`. The audit and repair should match, excluding drafts and pending sales, which are not final and will be costed at completion.

## Migration Plan

1. Ship the audit command alone. Read-only, no deploy anxiety, runnable on production immediately.
2. Run it and read the output — particularly the repairable/terminal split, the by-month cut, and the distance histogram. The by-month cut answers whether `SalesCostSnapshotService` is still generating zeros on new sales, which would be an ongoing leak rather than a backlog.
3. Choose `--max-distance-days` from the histogram.
4. Ship the repair with dry-run default. Run scoped by setting and date window, smallest slice first.
5. Verify a sample of anchored rows against their source purchases, then widen scope.

Rollback: reset `cost_unit_snapshot` and `cost_total_snapshot` to zero for the source labels this change introduces. Each rung is independently revertible.

## Open Questions

- Does any product have `stock_managed = false`? Resolved by the first audit run.
- What `--max-distance-days` value is defensible? Resolved by the distance histogram.
- Should the deferred backfill defects — the skip predicate and the per-product `$fallbackDate` — be scheduled as a follow-on change once this one has established a measurement baseline?
- Should bundle parent cost eventually derive from component costs, rather than remaining permanently zero?

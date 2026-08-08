## 1. Ground Truth Checks

- [ ] 1.1 Query production (or a production-shaped copy) for the count of `products` with `stock_managed = false`, and record the result in design.md's Open Questions
- [ ] 1.2 Confirm `sale_details.quantity` is stored in base units for converted-unit products, and that the purchase-derived landed cost is per base unit; document the finding before any write-mode task
- [ ] 1.3 Confirm bundle parent `sale_details` carry the cost snapshot and `sale_bundle_items` carries none, and note the expected size of the bundle-parent population

## 2. Shared Purchase Eligibility and Landed Cost

- [ ] 2.1 Extract the per-detail receipt-aware landed cost calculation out of `HistoricalReplayEngine::buildFallbackAverages()` into a method that prices a single `PurchaseDetail`, preserving approved-receipt preference, `calculateLineReceiptCost` proration, `calculatePurchaseDpp` fallback for `COMPLETED` only, and the `RECEIVED PARTIALLY`-without-receipt skip
- [ ] 2.2 Refactor `buildFallbackAverages()` to call the extracted method so no cost logic is duplicated
- [ ] 2.3 Add a single purchase-eligibility predicate (status in `RECEIVED`, `RECEIVED_PARTIALLY`, `COMPLETED`, excluding `RECEIVED_PARTIALLY` without approved receipts) reusable by both the audit and repair paths
- [ ] 2.4 Add a nearest-anchor resolver that, given product, cost bucket, and sale date, returns the nearest eligible purchase preferring on-or-before the sale date, then after, then cross-bucket, along with the signed day distance and the rung that matched
- [ ] 2.5 Add unit tests for the extracted landed cost covering tax-exclusive DPP, line discount, receipt proration, and the `RECEIVED PARTIALLY`-without-receipt skip
- [ ] 2.6 Add unit tests for the anchor resolver covering prior-preferred-over-nearer-future, future-only, cross-bucket, and no-eligible-purchase outcomes

## 3. Audit Command

- [ ] 3.1 Create `sales:audit-cost-snapshots` as a read-only command with `--setting`, `--product`, `--start`, `--end`, and a detail export option, exposing no write or force option
- [ ] 3.2 Implement in-scope selection restricted to parent sale status `Completed`, `DISPATCHED`, `RETURNED PARTIALLY`, `RETURNED`
- [ ] 3.3 Implement the missing-snapshot predicate as `cost_unit_snapshot <= 0 OR IS NULL`, with no dependence on `cost_snapshot_source`
- [ ] 3.4 Implement the repairable / terminal / non-stock-managed partition using the shared eligibility predicate from 2.3
- [ ] 3.5 Implement the totals report: in-scope, missing, covered
- [ ] 3.6 Implement breakdown by `setting_id`
- [ ] 3.7 Implement breakdown by sale year-month
- [ ] 3.8 Implement diagnostic breakdown by current `cost_snapshot_source`
- [ ] 3.9 Implement top-N products by missing count, each flagged with whether eligible purchase history exists
- [ ] 3.10 Implement the distance histogram over repairable rows using bounded day bands
- [ ] 3.11 Implement the detail export writing sale detail id, sale reference, sale date, setting, product, quantity, current cost, current source, and classification
- [ ] 3.12 Add feature tests asserting zero-cost rows with `BACKFILL_ZERO_FALLBACK`, `MISSING_AVERAGE_PRICE`, `CURRENT_AVERAGE_PRICE`, and null source all count as missing, and that positive-cost rows count as covered regardless of source
- [ ] 3.13 Add a feature test asserting the audit performs no database writes
- [ ] 3.14 Add a feature test asserting a row the audit classifies repairable is one the anchor resolver can actually anchor

## 4. Audit Deployment and Measurement

- [ ] 4.1 Ship the audit command on its own, with no repair path present
- [ ] 4.2 Run the audit on production and capture the full output
- [ ] 4.3 Record the repairable / terminal split, and decide from it whether the repair scope stands as designed
- [ ] 4.4 Read the by-month breakdown and record whether `SalesCostSnapshotService` is still producing zero-cost snapshots on recent sales; if so, raise it as a separate follow-on change rather than absorbing it here
- [ ] 4.5 Choose the `--max-distance-days` default from the distance histogram and record the rationale in design.md

## 5. Repair Path

- [ ] 5.1 Define the new source labels for prior-purchase anchor, later-purchase anchor, cross-bucket anchor, and no-purchase-history, ensuring none collide with `HPP_SNAPSHOT_IMPORT`, `BACKFILL_*`, `CORRECTION_REPLAY`, `NON_STOCK_ZERO`, `NON_STOCK_MANAGED`, `MISSING_AVERAGE_PRICE`, or `CURRENT_AVERAGE_PRICE`
- [ ] 5.2 Create the repair command with dry-run default, an explicit write option, `--max-distance-days`, and the same scope filters as the audit, exposing no force option
- [ ] 5.3 Implement candidate selection restricted to `cost_unit_snapshot <= 0 OR IS NULL`, skipping rows already carrying a repair-path source label
- [ ] 5.4 Implement anchored write: unit cost from the resolver, total as unit cost multiplied by sale detail quantity, source label per rung, and `cost_snapshot_at`
- [ ] 5.5 Implement recording of the anchor purchase detail identifier and signed day distance for each repaired row
- [ ] 5.6 Implement the distance guard so an anchor beyond `--max-distance-days` leaves the row at zero and is reported as skipped
- [ ] 5.7 Implement terminal labeling for products with no eligible purchase history, leaving cost at zero
- [ ] 5.8 Implement batched writes in bounded chunks, following the existing backfill flush pattern
- [ ] 5.9 Implement the per-rung outcome summary: repaired from prior, from later, from cross-bucket, labeled no-purchase-history, skipped for distance, skipped as covered
- [ ] 5.10 Add a feature test asserting a positive-cost snapshot is never overwritten under any option combination
- [ ] 5.11 Add a feature test asserting dry-run mode writes nothing while reporting the same rows write mode would touch
- [ ] 5.12 Add a feature test asserting a prior purchase is chosen over a nearer future purchase
- [ ] 5.13 Add a feature test asserting no-purchase-history rows keep zero cost, receive the terminal label, and are skipped on a second run
- [ ] 5.14 Add a feature test asserting the distance guard leaves a row at zero rather than anchoring on distant evidence
- [ ] 5.15 Add a feature test asserting repeated runs over unchanged data are idempotent

## 6. Backfill Interoperability

- [ ] 6.1 Update `BackfillSalesCostSnapshotsCommand` skip logic so sale details carrying a repair-path source label are left unchanged in non-force mode
- [ ] 6.2 Add a regression test asserting the backfill does not overwrite repair-path snapshots without force
- [ ] 6.3 Confirm by test that the backfill still leaves `HPP_SNAPSHOT_IMPORT` untouched in both force and non-force mode

## 7. Repair Rollout

- [ ] 7.1 Run the repair in dry-run mode on the narrowest slice (single setting, single month) and compare the planned outcome against the audit numbers for that slice
- [ ] 7.2 Run write mode on that slice, then hand-verify a sample of anchored rows against their source purchase documents
- [ ] 7.3 Re-run the audit on the slice and confirm the missing count fell by the expected amount
- [ ] 7.4 Widen scope progressively by setting and date window, re-auditing after each pass
- [ ] 7.5 Document the rollback procedure — resetting cost to zero for a given repair source label — in the change and verify it on a non-production copy

## 8. Verification and Documentation

- [ ] 8.1 Run the focused test suite for the Sale and Purchase modules and confirm no regressions
- [ ] 8.2 Add both commands with their options and intended sequence to README alongside the existing `sales:backfill-cost-snapshots` entry
- [ ] 8.3 Record in design.md's Open Questions whether the deferred backfill defects — the source-prefix skip predicate and the per-product `$fallbackDate` at lines 134-148 — should become a follow-on change

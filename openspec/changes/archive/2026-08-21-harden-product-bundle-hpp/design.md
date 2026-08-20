## Context

Normal Sales persists one commercial `sale_details` row for a bundle parent and non-billable `sale_bundle_items` for composition. POS additionally splits one captured bundle across owner-specific Sales documents according to physical stock allocations. Component dispatch and inventory movement already follow the physical product and owner, but live HPP snapshotting runs only for the parent-shaped `SaleDetails` row. `sale_bundle_items` has no cost columns, operational HPP queries read only `sale_details`, and POS component-only groups can snapshot the stock-managed parent product even though that group did not fulfill it.

The change must preserve customer-facing bundle revenue, tax, receipt, stock, serial, and return behavior. Informational component prices remain revenue allocations. Non-stock content has verified zero HPP. Stock cost resolution uses positive average purchase prices only and respects the physical owner's PKP classification. Historical imported parent HPP remains authoritative.

## Goals / Non-Goals

**Goals:**

- Persist immutable HPP identity for every physically fulfilled bundle parent and component.
- Use the stock owner's average first and deterministic same-PKP then opposite-PKP nearby fallback.
- Prevent omitted component HPP and false parent HPP in POS component-only split groups.
- Provide one exact-once net Sale HPP aggregate for operational reports.
- Reverse original immutable HPP for effective returns and separately cost replacement dispatches.
- Preserve truthful missing-cost evidence without blocking transaction completion.

**Non-Goals:**

- Reprice bundle revenue or change informational component-price semantics.
- Add cost to genuinely non-stock-managed products or services.
- Replace moving-average purchase calculation or introduce FIFO/specific identification.
- Use `last_purchase_price` as live bundle HPP fallback.
- Move existing Normal Sales parent snapshot timing from creation to dispatch.
- Redesign POS owner splitting, Sales Return commercial settlement, inventory valuation, or accounting journals.
- Run or require the complete application test suite as part of this change.

## Decisions

### 1. Persist component cost on `sale_bundle_items`

Add nullable decimal `cost_unit_snapshot(15,6)` and `cost_total_snapshot(15,2)`, nullable string `cost_snapshot_source`, nullable foreign-key-compatible `cost_snapshot_setting_id`, nullable boolean `cost_snapshot_setting_is_pkp`, and nullable timestamp `cost_snapshot_at`.

The existing row already carries component product, expanded quantity, parent Sale detail, Sale, bundle, and group identity. Keeping cost on that physical row preserves return, owner, dispatch, serial, and replay traceability. Component cost is not aggregated into the parent snapshot.

Alternative considered: add all component cost to `sale_details.cost_total_snapshot`. Rejected because it destroys product/owner identity and makes partial component returns and split-owner audit ambiguous.

Alternative considered: persist component snapshots and an aggregate parent component cost. Rejected because current reports already calculate from unit cost and quantity; two authoritative representations create a direct double-counting risk.

### 2. Centralize owner-aware average-cost resolution

Introduce a resolver returning a value object/array containing unit cost, source label, selected setting ID, selected setting PKP state, and whether the result is missing. Both parent and component snapshotting use it.

Resolution for a stock-managed product and physical owner is:

1. Owner's strictly positive `product_prices.average_purchase_price`.
2. Settings represented by the owner's enabled `setting_sale_locations`, ordered by position, filtered to the owner's `is_pkp` value.
3. The same configured settings with the opposite `is_pkp` value.
4. Remaining settings not yet considered, same-PKP first and opposite-PKP second, each ordered by setting ID.
5. Explicit missing-average zero when no positive candidate exists.

Multiple locations owned by one setting collapse to its earliest enabled position. Equal positions use setting ID. Zero, null, blank, negative, and non-finite values are unavailable. The chosen setting and PKP state are persisted so fallback remains auditable after configuration changes.

Alternative considered: arbitrary first nonzero product-price row. Rejected as nondeterministic and tax-class unaware.

Alternative considered: `last_purchase_price` fallback from the original exploration guide. Rejected by the clarified business decision that bundle HPP must use an available average purchase price, preferring nearby businesses of the same PKP class.

### 3. Snapshot the physical posting shape, not the commercial row shape

Extend the snapshot API with explicit posting context: physical owner setting, fulfilled quantity, and fulfillment classification. It must not infer physical fulfillment solely from the product attached to a commercial Sale detail.

For POS:

- Parent fulfilled by group: snapshot parent using that group's source setting and parent allocated quantity.
- `parent_not_fulfilled_by_group`: persist zero parent cost with `NOT_FULFILLED_BY_GROUP`.
- Component fulfilled by group: snapshot that group's persisted `SaleBundleItem` using component product, owner-group setting, and expanded allocated quantity.
- Non-stock component: persist zero with `NON_STOCK_MANAGED`.

For Normal Sales, the single Sale/dispatch owner is the physical owner for parent and components. Snapshot timing stays at Sale creation/update to match existing parent behavior. POS snapshot timing remains atomic finalization, which also creates approved dispatch and inventory movement.

### 4. Make missing HPP visible but non-blocking

Missing stock HPP persists zero with `MISSING_AVERAGE_PRICE`, selected setting null, and snapshot time. Normal Sales returns a session warning after successful persistence; POS finalize returns structured warning entries that the existing completion response can surface and logs the affected Sale/product identity. A verified non-stock zero and a parent-not-fulfilled zero do not warn.

The warning is emitted after successful transaction completion, never by throwing inside the transaction. This preserves the rule that missing cost does not block the Sale.

### 5. Use a shared net HPP aggregate

Create a report-oriented query/service that calculates per Sale and scoped period:

```text
gross parent HPP
 gross component HPP
- effective returned HPP
= net recognized HPP
```

Parent and component gross HPP use persisted unit snapshot multiplied by the accounting quantity. `NOT_FULFILLED_BY_GROUP` parent rows contribute zero. Revenue continues to come only from commercial Sale/Sale-detail values; `sale_bundle_items` never becomes a second revenue source.

Profit/loss and `OperationalMovementEventService` consume the shared aggregate. General ledger, trial balance, and balance-sheet earnings inherit the same result through their existing service composition. Focused tests prove opening and period calculations agree.

Alternative considered: independently join `sale_bundle_items` in every report. Rejected because repeated SQL would drift and can multiply parent rows when a Sale detail has several components.

### 6. Persist immutable return-cost effects

Add nullable HPP reversal fields to `sale_return_details`: unit cost, cost quantity, total cost, source, original snapshot setting metadata, snapshot time, and an origin discriminator/reference capable of identifying `sale_details` versus `sale_bundle_items`. Existing `dispatch_detail_id` and POS execution context remain lineage inputs.

At effective physical receipt/final approval, the return workflow copies the original persisted unit snapshot and multiplies it by received quantity. It does not resolve current average cost. Rejected, blocked, rolled-back, or merely drafted details do not become effective report reversals.

POS cash bundle return persists parent and component reversals from the approval plan's existing `component_sale_bundle_item_id` mapping. The existing commercial quantity reduction may remain for customer settlement and eligibility compatibility, but reports stop using mutable Sale quantity as the sole reversal mechanism.

For replacement:

- Receipt reverses original HPP only for products physically received.
- Parent-only bundle replacement leaves component HPP unchanged.
- Approved replacement dispatch persists a new outgoing snapshot using the replacement dispatch owner and current owner-aware resolver.
- Idempotency keys/origin references prevent duplicate reversal or outgoing effects.

Alternative considered: continue deriving HPP solely from mutated current Sale quantities. Rejected because standard and POS return paths differ, historical facts are overwritten, and replacement dispatch cannot be represented correctly.

### 7. Extend backfill without rewriting authoritative parent HPP

Bundle-aware historical replay selects `sale_bundle_items` with absent eligible snapshots, associates them with Sale date/owner and physical product, and applies existing effective-date replay rules where historical cost is available. It reports parent and component updates separately. Existing `HPP_SNAPSHOT_IMPORT` parent snapshots remain untouched, and component cost is never rolled into an imported parent value.

The implementation must explicitly decide how legacy component-only POS groups recover their physical source owner from Sale owner, dispatch detail, and stored group identity; rows without unambiguous lineage are warned and skipped rather than guessed.

## Risks / Trade-offs

- [Legacy component rows may lack unambiguous physical owner/dispatch lineage] → Backfill only deterministic rows and report skipped identities with Sale, detail, bundle item, and product IDs.
- [Joining parent and multiple component rows can multiply HPP] → Aggregate parents and components independently by Sale before combining them.
- [Existing POS cash return mutates Sale quantities] → Make persisted effective return-cost rows authoritative for HPP and add regression tests preventing an additional quantity-derived subtraction.
- [Nearby fallback changes when location configuration changes] → Persist selected source setting and PKP state; retries of an already-posted Sale use stored snapshots.
- [Missing-cost warnings may be lost by UI redirects] → Persist source evidence and use established session/structured POS completion warning channels; logging is supplementary, not authoritative.
- [Backfill and correction replay can overwrite new fields inconsistently] → Extend both through the shared resolver/replay abstraction and preserve imported parent source protection.
- [Migration of large `sale_bundle_items` and `sale_return_details` tables can lock production] → Use additive nullable columns and database-compatible indexes/foreign-key handling; backfill data separately from schema deployment.

## Migration Plan

1. Add nullable component and return HPP columns without rewriting historical rows.
2. Deploy models, owner-aware resolver, and write paths so new Sales and returns persist complete snapshots.
3. Deploy the shared report aggregate and focused report reconciliation tests together with write support.
4. Extend dry-run-first backfill/replay tooling to preview eligible component rows, skips, warnings, and expected HPP deltas.
5. Run focused canonical, split-owner, fallback, reporting, and return regression tests.
6. Execute dry-run backfill in staging, inspect skipped/ambiguous rows, then run an explicitly authorized write in bounded batches if accepted.

Rollback disables new readers/writers while leaving additive nullable columns intact. Historical data written to the new columns remains harmless to the old parent-only readers; dropping populated columns is not part of operational rollback.

## Open Questions

- None blocking implementation. The configured sales-location priority followed by deterministic remaining-setting order is the defined meaning of "nearest" for this change.

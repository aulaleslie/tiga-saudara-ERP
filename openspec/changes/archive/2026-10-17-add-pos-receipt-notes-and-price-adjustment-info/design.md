# Design

## Context

See proposal.md for motivation. Current completed receipt paths share `PosReceiptReprintProjectionService` and `pos::receipt`; `PosReceiptService` supplies original and preview data. POS transaction lines persist original unit prices and packed metadata. Sale details persist quantity and unit price with `pos_transaction_line_id` for modern checkouts. Dispatched monetary editing updates those details in place. General edits can recreate them, so legacy lineage is not universal.

Packed metadata preserves original box/loose prices, counts, factor and labels, but Sales persists a blended unit price rather than independent current box/loose prices. Current receipt unit prices can be derived from assigned net amounts containing header adjustments; these are unsuitable as unit-price-change evidence. Standard cash returns reduce Sale detail quantity/subtotal while retaining unit price; return lines and source snapshots preserve return-time evidence. Internal bundle allocation prices are not customer selling prices.

The current projection reads live settlement but, without completed returns, retains some checkout payment presentation. An edited debt receipt with later payments needs focused verification of that branch rather than assuming all amounts already reconcile.

## Goals / Non-Goals

**Goals:** Add explanatory metadata using persisted provenance; preserve existing financial projection, historical fallback, endpoint permissions and print audit semantics; fix receipt-only payment presentation where focused evidence reveals a gap.

**Non-Goals:** Schema changes, Sales edit UI or persistence redesign, separate current pack prices, price audit timelines, catalog lookups, return settlement redesign, snapshot backfill, changing the PPN footer or signature space, and full-suite verification.

## Decisions

### Source note from the resolved POS transaction

Expose one receipt note field from the transaction already resolved by each entry point. For split checkout groups use the same canonical customer transaction selected for receipt identity; do not concatenate sibling notes or guess from Sales notes. For checkout paths resolve through existing receipt transaction relationships. Missing source means no note. Historical output uses the existing current transaction note, not a claimed historical note snapshot. Blade escapes text and preserves newlines with wrapping CSS. Place it after payments and before return history or tail spacing. Reusing checkout or Sales notes was rejected because their content and provenance differ.

### Separate price evidence from allocated receipt charges

Add an optional explanatory payload per receipt item, built from immutable original transaction-line unit price and proven linked Sale detail unit prices. Compare integer minor units and match unit basis before comparison. Reuse batched line mapping and eager-loaded records rather than per-row queries. Never compare `assignedAmount / quantity`, normalized net charges, live catalog prices or bundle allocation rows as if they were raw selling prices.

For standard items, group relevant current detail quantities by distinct current unit price, retaining labels and original unit basis where provable. Completed cash returns reduce remaining quantities; replacement execution must not duplicate customer quantity or treat revenue transfers as negotiated prices. If a bundle's customer selling price cannot be proven independently of owner allocations, omit numeric explanatory data rather than compare allocation prices to the whole bundle. Existing current financial mapping refusal still applies independently; explanatory uncertainty alone does not add a new receipt refusal.

No new transaction-price persistence is needed for supported standard lines. A printed-price snapshot or complete edit timeline was rejected because checkout-to-current comparison is sufficient. Returning a price to checkout value clears the notice.

### Packed fallback is a notice, not a reconstructed comparison

For proven packed-line lineage, compare stored original versus current blended unit-price fields at matching precision and basis. Emit the generic Indonesian notice only on a proven change. Preserve existing packing presentation and use persisted factor/labels when available. Do not infer separate new box and loose prices from subtotal allocation, proportional scaling or live conversion definitions. Exact pack/loose comparisons would require broader Sales persistence changes and are deferred.

### Return history freezes the comparison endpoint

For fully returned standard items, resolve original POS line through persisted direct or otherwise proven existing lineage, then compare against saved return-line/source-snapshot price, with its matching unit basis. Attach the explanatory payload to return-history items and label `Harga satuan saat retur`. Do not use the latest mutable Sale price as the return endpoint, reconstruct overwritten component prices or mutate return records. Packed ambiguity follows the same conservative rule: no fabricated numeric decomposition.

### Settlement remains authoritative

Use existing transaction settlement projection and persisted effective payment evidence for totals, paid amounts, methods and live debt, including the no-return branch. Reuse the existing reconciliation helper where valid; fix only receipt presentation if checkout tender rows become stale after later payments or edits. Preserve cash tender/change semantics for unchanged checkout cases and keep historical tender/change on historical output. Reject contradictions through existing projection exceptions instead of clipping or redistributing money across methods without evidence. The change neither authorizes additional Sales edits nor changes existing overpayment guards.

### Focused verification

Extend existing POS receipt tests rather than build a parallel broad suite. Include one actual authorized monetary edit plus later payment followed by receipt generation to establish financial behavior, not only mocked view payloads. Parameterize endpoint parity using existing fixtures. Cover notes, standard split prices, non-trigger adjustments, catalog independence, packed notices, fully returned snapshots and historical/previews. Run named files or precise filters only. Inspect representative 72 mm output for note wrapping and annotations without changing existing footer/tail sections.

## Risks / Trade-offs

- [Legacy or recreated rows lack lineage] → Reuse existing mapping rules and historical fallback; omit unsupported annotations.
- [Packed blended prices cannot identify changed selling unit] → Generic notice; defer exact decomposition until Sales persists it.
- [Discount/tax normalization looks like a selling-price edit] → Compare proven raw unit prices on matching basis, with focused non-trigger checks; omit annotation where equivalence cannot be established.
- [Split bundle or replacement allocations look like customer prices] → Exclude internal allocations and require commercial lineage; never compare owner revenue shares to whole-item prices.
- [No-return payment rows use stale checkout values] → Exercise an actual debt adjustment and later payment, and narrowly reuse live reconciliation in receipt projection.
- [Return snapshots have different unit semantics] → Label return-time endpoint and require unit identity; never infer from net return amounts.
- [Historical notes were not snapshotted] → Explicitly display the existing transaction note without implying checkout-time immutability.

## Migration Plan

No migrations or backfill. Deploy receipt service/view changes after focused verification. Rollback restores previous receipt rendering; persisted transactions, Sales, payments and returns are unchanged by this feature. Preserve the archived receipt-return change as reference history.

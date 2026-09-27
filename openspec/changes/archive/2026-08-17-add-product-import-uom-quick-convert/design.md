## Context

`products.base_unit_id` is the accounting/stock unit; `product_stocks` and `products.product_quantity` hold live quantities. Under normal operation, stock arrives via an approved `ReceivedNoteDetail`, which creates a `BUY` `transactions` row and increments stock. The existing "Normalisasi UOM Penerimaan" tool (`Modules/Purchase/Services/UomNormalizationEligibilityService.php`, spec `received-purchase-uom-normalization`) corrects base-UOM errors for exactly that lineage: it replays receipt history, requires every purchase line to be selected and fully received, and hard-blocks on any `IMP`/`INIT`/unexplained-stock transaction type.

A separate class of products was populated by a bulk "SALES PRICE & STOCK SNAPSHOT IMPORT" operation: a `transactions` row of `type = ADJ` sets `product_stocks`/`product_quantity` directly, with `received_note_detail_id = NULL` and no `ReceivedNoteDetail` ever created. `purchase_details` rows may separately exist for these products (imported historical purchase records), but their quantities do not reconcile with live stock at all — confirmed empirically (e.g. one product's `purchase_details` sum is 5291 while live stock is 43; another sums to 607 while live stock is 0). `purchase_details` is historical/reference data only for these products, not a source of truth for current stock.

This design covers a lightweight, CLI-only correction path for this second class of product, scoped much smaller than the existing tool because there is no receipt ledger to replay — only a live snapshot to rebase.

## Goals / Non-Goals

**Goals:**

- Correct one product's base UOM (unit + factor) by rebasing its current live stock and cost basis in place, without touching `purchase_details`.
- Guarantee the corrected quantity is not silently wrong by verifying transaction-ledger self-consistency and the complete absence of any dispatch/fulfillment history before mutating.
- Prevent post-conversion silent wrong-quantity dispatch from stale draft documents by removing (not merely blocking on) any undispatched, unpaid POS or Sales document referencing the product, and reporting exactly what was removed.
- Produce an immutable audit record sufficient to explain the correction after the fact.
- Run safely against a production-mirrored database without requiring another import cycle or data cleanup.

**Non-Goals:**

- No receipt/BUY ledger replay — this path is only for products with zero dispatch/sale history, so there is nothing to replay.
- No handling of mixed provenance (some `BUY`-sourced stock and some `ADJ`-sourced stock for the same product) — the ledger-integrity guard does not by itself distinguish this case from a clean one; mixed provenance is treated as out of scope for v1 and should be refused if detected during implementation review, deferring to the existing receiving-normalization tool or a future extension.
- No cross-setting rebase, barcode migration, or `product_unit_conversions` factor propagation beyond the simple case — these are refused (not attempted) when detected, per the deferred-scope decision below.
- No UI; this is an operator-run artisan command only.
- No compensating logic for `broken_quantity` — any non-zero broken bucket blocks the correction (same conservative policy as the existing tool), since there is no way to know which unit a broken quantity was recorded in.

## Decisions

### Source of truth is live stock, not purchase history

`product_quantity` / `product_stocks` (current values) are multiplied by `factor`. `purchase_details.quantity` is never read for math and never written. This was confirmed necessary by direct data inspection: `purchase_details` sums do not reconcile with live stock for products with multiple purchase lines, because the "SALES PRICE & STOCK SNAPSHOT IMPORT" adjustment is an independent physical-count snapshot, not a sum of purchase records.

**Alternative considered**: backfill synthetic `ReceivedNoteDetail`/`BUY` rows so the product becomes eligible for the existing Normalization tool. Rejected — this fabricates receiving history that never happened, requires reconciling away the real `ADJ` transaction without corrupting the running-balance ledger, and only cleanly covers the no-mixed-provenance case anyway. A dedicated, honest path is simpler and does not launder import stock into looking like receiving stock.

### Eligibility is proven by ledger self-consistency + absence of fulfillment, not by transaction type

Rather than allow-listing transaction types (e.g. "only ADJ is allowed"), the command checks two independent facts:

1. **Ledger integrity**: the most recent `transactions` row for the product (globally and per location) must have `after_quantity` / `after_quantity_at_location` equal to the live `products.product_quantity` / `product_stocks.quantity` right now. This proves the transaction ledger has not drifted from reality (no untracked mutation occurred outside the ledger). Verified true across sampled products regardless of transaction-type mix.
2. **No fulfillment ever**: no `DISPATCH`-type `transactions` row exists for the product, and no `sales` row referencing the product (via `sale_details`) has `status IN ('DISPATCHED','RETURNED','RETURNED PARTIALLY')` or `paid_amount > 0`.

Ledger integrity alone does not prove "no sales happened" — a product with real `DISPATCH` history can still have a perfectly self-consistent ledger. Both checks are required together.

**Alternative considered**: allow-list `ADJ` as the only acceptable transaction type. Rejected — too brittle; a product that legitimately has both old `BUY` history and a later `ADJ` snapshot correction would be wrongly excluded, and the real safety property needed ("nothing has moved since the number we're about to multiply was set") is better expressed as "no fulfillment occurred," which is enforceable directly.

### Undispatched, unpaid documents are removed, not merely blocked

POS carts (`pos_transactions` status `DRAFT`/`LOADED`) and Sales (`status` not dispatched/returned, `paid_amount = 0`) referencing the product are force-deleted as part of the same command run, rather than causing the command to refuse.

This is necessary, not just a convenience: checkout/finalize code for both POS (`InlinePosCheckoutPostingAdapter`, `PosCheckoutSplitPlannerService`) and the cart-hydration path (`PosTransactionSnapshotMapper::hydrateCart`) never re-resolves a line's `qty`/`conversion_id` against the product's *current* `base_unit_id` or a live conversion factor — the factor is applied exactly once, at add-to-cart time, in `PosCartService::addLineWithinLock`. The existing `snapshot_hash` drift-detection guard hashes `conversion_id` and `qty`, not `base_unit_id`, so it does not detect this staleness either. If left in place, a stale draft/undispatched Sale would silently dispatch the wrong quantity (off by the full conversion factor) the next time it is completed. Deleting the whole document (rather than just the offending line) avoids leaving a half-emptied cart that would confuse the cashier or salesperson who created it.

No stock reservation exists for either POS drafts or undispatched Sales — `PosCartService`'s `available_qty` is computed live from `product_stocks`, ignoring pending drafts entirely — so no compensating stock release is needed when deleting these documents.

**Alternative considered**: reuse `PosTransactionService::cancel()` for POS deletion. Rejected as the deletion mechanism itself — that method only accepts `status === DRAFT` (excludes `LOADED`) and is gated behind `pos.void` permission / supervisor approval, both the wrong fit for a privileged, operator-run bulk correction that must cover both statuses unconditionally. The command performs its own direct deletion instead, but should model status coverage on `cancel()`'s intent (DRAFT + LOADED both in scope here).

### Cost basis is divided, existing conversions are refused rather than propagated (initial scope)

`average_purchase_price` / `last_purchase_price` are divided by `factor`. If the product already has any `product_unit_conversions` rows, or a non-null `products.barcode`, or stock/price footprint in more than one `setting_id`, the command refuses rather than attempting the fuller propagation logic the existing Normalization tool implements for those cases (conversion-factor multiplication, barcode registry migration, per-setting price rebase). This keeps the initial implementation small; these cases can be added later once observed in practice.

**Alternative considered**: implement full conversion/barcode/cross-setting handling up front, mirroring the existing tool. Rejected for v1 — adds significant complexity for cases not yet confirmed to exist among the target product set; "refuse and report why" is safer than guessing, and the guard makes the gap visible rather than silent.

## Risks / Trade-offs

- [Mixed BUY + ADJ provenance for the same product] → Ledger-integrity check alone does not catch this. Implementation must explicitly check for any `BUY`-type transaction as an additional refusal condition, or the correction could apply to only part of a product's true stock history. Treat as a hard blocker until this class of product is otherwise handled.
- [Deleting a Sale/POS document loses a real, if unfulfilled, business record] → Mitigated by requiring `paid_amount = 0` and no dispatch before deletion (no money or fulfilled goods are lost), and by reporting every deletion in the command output so the operator can follow up with the affected cashier/salesperson.
- [Factor entered incorrectly] → `--dry-run` prints the full before/after impact (quantities, cost basis, documents to be removed) without mutating; operator must re-run without the flag to execute.
- [Precision loss when dividing cost basis by factor] → Use higher internal precision during calculation and store/display the rounded result, consistent with the existing tool's approach; record any rounding effect in the audit row.
- [Broken stock exists] → Hard block, consistent with the existing tool's conservative policy; no code path multiplies broken quantities.

## Migration Plan

1. Add the audit table/model for this correction (product, old/new unit, factor, before/after quantities and cost basis, reason, actor, timestamp, list of removed documents).
2. Implement the eligibility service (ledger integrity, fulfillment-history, broken-stock, conversion/barcode/cross-setting refusal checks) as a standalone class, unit-testable independent of the command.
3. Implement the mutation service (multiply/divide/flip, delete qualifying documents, write audit row) inside a single database transaction with row locking on the product.
4. Implement the artisan command as a thin wrapper: parse arguments, call eligibility, print `--dry-run` preview or call mutation, print the removed-documents report.
5. No rollback path is provided for a completed correction (consistent with the existing tool's stance) — `--dry-run` and mandatory `--reason` are the safeguards; the audit row preserves before/after facts for manual reconciliation if ever needed.

## Open Questions

- Should mixed `BUY` + `ADJ` provenance be explicitly detected and refused in v1 (recommended), or is it acceptable to discover this only via the ledger-integrity/fulfillment checks failing indirectly?
- Is a dedicated new audit table preferred, or should this reuse/extend the existing `uom_normalization_batches`/`uom_normalization_lines` tables from the receiving-normalization feature (tagged to distinguish import-origin corrections)?

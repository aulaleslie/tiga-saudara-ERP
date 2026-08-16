## Context

`Modules/Sale/Http/Controllers/SaleController.php` implements both `storeDispatch()` and `approveDispatch()`.

- `storeDispatch()` computes outstanding demand (parent `sale_details` + `sale_bundle_items` quantities minus existing pending/approved `dispatch_details` quantities, keyed by `product_id + tax_id + bundle_id`) inside a `Validator::after()` closure that runs before `DB::beginTransaction()`.
- `approveDispatch()` checks `$dispatch->isPending()` before `DB::beginTransaction()`, and inside the transaction only locks `ProductStock` rows during mutation — the `Dispatch` row itself is never locked or re-checked.
- `approveDispatch()` reads `$detail->product->stock_managed` (live) rather than any value captured at submission time; no column on `dispatch_details` currently stores that decision.

An investigation confirmed all three gaps against current code and found no existing concurrency/race test coverage for either path. It also confirmed the `product_id + tax_id + bundle_id` fulfillment key is already applied consistently by both submission and dispatch-index paths, and that POS checkout finalize already has its own independent transaction/idempotency boundary untouched by these paths.

## Goals / Non-Goals

**Goals:**

- Guarantee pending + approved dispatch quantity never exceeds locked authoritative demand, even under concurrent submission.
- Guarantee one dispatch approval applies its effects at most once, even under concurrent approval.
- Guarantee a dispatch detail's inventory-routing decision is fixed at submission time and cannot silently flip if the product's classification changes before approval.

**Non-Goals:**

- No centralized fulfillment-key builder extraction — not required by any of the three fixes, and the key logic is already applied consistently.
- No POS regression reconciliation — POS posting is untouched.
- No rewrite of non-stock acknowledgement lifecycle, completion-by-obligation semantics, or historical dispatch data.

## Decisions

### 1. Lock the Sale row inside the submission transaction

Keep the existing `Validator` for early, cheap rejection of malformed input, but move the authoritative outstanding-demand recomputation into `DB::transaction()`, locking the `Sale` row first with `lockForUpdate()`, then reloading demand and active dispatch quantities before accepting or rejecting the request. The Sale lock is the serialization boundary because every competing submission for the same Sale shares `sale_id`.

### 2. Lock the Dispatch row first inside the approval transaction

Move `isPending()` off the pre-transaction check. Inside `DB::transaction()`, load the dispatch with `lockForUpdate()` and re-verify it is still pending before doing anything else. A concurrent request that loses the lock race observes the already-committed non-pending status and exits without side effects.

### 3. Add a nullable routing snapshot, populated from data already computed at submission

`storeDispatch()` already resolves whether each line is inventory-managed while building the aggregated demand structure. Persist that resolved value onto the new nullable `dispatch_details` column instead of discarding it. Approval reads the snapshot; a live-classification mismatch is treated as a conflict requiring the dispatch to be rejected/recreated, not silently reinterpreted.

Legacy rows (submitted before this change) have a null snapshot; infer conservatively from existing inventory-specific fields (location/serial), and fail closed on ambiguity rather than guessing.

## Risks / Trade-offs

- [Sale-row lock reduces throughput for many concurrent dispatches against one Sale] → Keep the locked section limited to this Sale's own demand/dispatch rows.
- [Deadlock between Sale/Dispatch/ProductStock locks] → Preserve existing lock order (Sale or Dispatch first, then ProductStock), consistent with current `adjustStockForDispatchDetail()` behavior.
- [Legacy null-snapshot rows may be ambiguous] → Fail closed with an actionable message; do not guess silently.

## Migration Plan

1. Add the nullable snapshot column (additive, no backfill).
2. Deploy atomic submission and locked approval together (both touch the same controller methods).
3. Run the new targeted tests plus existing dispatch-approval tests; full-suite run is optional, not required, before release.

Rollback is a code rollback; the nullable column can remain in place harmlessly.

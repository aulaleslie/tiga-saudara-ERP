## Context

Two independent POS defects, each root-caused in exploration.

**Receipt subtotal (÷100):** `PosCartService::buildSnapshot()` always runs `PosCartTotalsCalculator::calculate()`, which emits every line's `line_total` in Rupiah via `fromMinor()`. `PosTransactionSnapshotMapper::persistLines()` then stores that Rupiah value into `line_meta['line_total']`. But `PosReceiptService` still computes `$lineGross = (float) $line->line_meta['line_total'] / 100`, a leftover assumption from when only PACKED lines set `line_total` (in cents). The result: every line's printed subtotal is 100× too small. The grand total is unaffected because it reads `totals.grand_total` (already Rupiah), and the per-unit breakdown is unaffected because it reads `unit_price` directly. Only the packed unit-breakdown (`buildPackedUnitBreakdown`) still legitimately reads cents from `breakdown.box_price_applied` / `loose_price_applied`.

**Qty-approval leak:** In `sell.blade.php`, `clientPendingApprovals` is a single per-line slot (`{ lineId: {...} }`) with no action-type discriminator. The PRICE_OVERRIDE submit handler writes into it, and the quantity-cell renderer reads it as the qty-reduce fallback (`qtyReduceRaw = backendQtyReduceReq || clientPending`), so a price-override pending entry renders the quantity − control as "Periksa". The server snapshot's `pending_approvals` is already correctly filtered by `action_type`; only the client fallback is unscoped.

## Goals / Non-Goals

**Goals:**
- Receipt line subtotal equals the Rupiah line total on completed, draft, and loaded-transaction receipts.
- Packed lines keep correct per-unit Rupiah prices in their breakdown.
- The quantity −/"Periksa" control reflects only QTY_REDUCE requests; price-override state never appears there.

**Non-Goals:**
- No change to totals math (grand total, tax, discounts are already correct).
- No change to how `line_total` is computed or stored (the stored Rupiah value is authoritative; the receipt read is what's wrong).
- No change to the server approval snapshot, which is already action-typed.
- No DB, migration, or API changes.

## Decisions

**1. Fix the receipt read, not the stored value.** Remove the `/100` on `line_meta['line_total']` in both `getReceiptData()` and `getTransactionReceiptData()`, treating the persisted value as Rupiah. Rationale: the snapshot/persistence pipeline is the single source of truth and already normalizes to Rupiah for all line types; changing storage would ripple into totals and return flows that already consume the Rupiah value correctly. Keep the fallback `qty * unit_price` branch (already Rupiah). Keep `buildPackedUnitBreakdown`'s `/100` (those fields are genuinely cents).
   - Alternative considered: store `line_total` in cents to match the receipt. Rejected — it would break `PosCartTotalsCalculator` output consumers and return-side reads that expect Rupiah.

**2. Scope client pending-approvals by action type.** Key `clientPendingApprovals` per line AND action type (e.g. `clientPendingApprovals[lineId] = { QTY_REDUCE: {...}, PRICE_OVERRIDE: {...} }`), and have the qty-reduce renderer read only the `QTY_REDUCE` entry. Rationale: preserves the existing optimistic-UI fallback used when the post-submit snapshot refresh fails, while eliminating cross-action leakage. Writers (qty-reduce submit, price-override submit) each write only their own action key; clearers remove the matching key.
   - Alternative considered: drop the client fallback entirely and rely solely on the server snapshot's `pending_approvals`. Simpler, but loses the optimistic render when the refresh call fails, which the current code deliberately handles.

## Risks / Trade-offs

- [Receipt fix regresses packed lines] → Packed lines route their per-unit price through `buildPackedUnitBreakdown` (untouched) and their subtotal through the same `line_meta['line_total']` (now Rupiah, matching how it's persisted). Covered by a packed-line receipt test.
- [Historical receipts already printed with wrong subtotal] → This is a display-time fix; reprints of past transactions will now render correctly since the stored value was always Rupiah. No data backfill needed.
- [Client keying change misses a clear path] → Enumerate all `clientPendingApprovals[lineId]` reads/writes/deletes and update each to the action-scoped shape; add a regression test asserting a price-override request leaves the qty − control unchanged.

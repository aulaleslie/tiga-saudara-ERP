# Proposal

## Why

Approved purchase receivals currently have no safe reversal path, leaving operators unable to correct an erroneous receipt without deleting history or manually repairing stock. Purchase edits can also invalidate pending receival detail links, so reopening a purchase requires an auditable way to cancel stale pending receivals while preserving their history.

## What Changes

- Add one permission-controlled "Batalkan Penerimaan" action on the Purchase detail page that cancels every `APPROVED` and `PENDING` receival of that Purchase together; selecting individual receivals, lines, or quantities is not supported.
- Validate all affected receivals before any mutation; if any receival cannot be safely reversed, cancel none of them.
- Retain cancelled receival headers, details, serial links, approval evidence, and immutable product/UOM/tax/location snapshots with a clear `CANCELLED` status, actor, timestamp, reason, and cancellation origin.
- Reverse an approved receival through compensating inventory transactions while retaining its original `BUY` transactions and rejecting cancellation when stock, serial lineage, supplier returns, normalized data, or legacy provenance cannot be reversed safely.
- Block cancellation when any attributed serial is sold or otherwise no longer active and available at the original receipt location, or when the exact original stock bucket lacks sufficient quantity.
- Return the Purchase to `APPROVED` because no effective receival remains after the purchase-level cancellation, and resolve approval notifications of cancelled pending receivals while preserving them as history.
- Prevent full Purchase editing while any pending receival remains, and ensure cancelled receival history survives subsequent replacement of Purchase detail rows.
- Recalculate affected purchase-cost history after reversal without changing Purchase commercial amounts or payments.
- Add focused feature and service verification for authorization, lifecycle, inventory, serial, history-preservation, dependency, legacy-provenance, and concurrency behavior; no full test-suite run is planned for this change.

## Capabilities

### New Capabilities

- `purchase-receival-cancellation`: Purchase-level, all-or-nothing cancellation of every pending and approved receival of a Purchase, including authorization, reversal safety, audit history, stock/serial effects, cost replay, and preserved display history.

### Modified Capabilities

- `purchase-receiving-approval-lifecycle`: Treat cancelled receivals as ineffective, serialize cancellation with competing Purchase mutations, and prevent full Purchase edits while pending receivals exist.

## Impact

- Affects the Purchase detail page (Penerimaan Barang history and the purchase-level cancellation action), Purchase receival statuses, persistence, history UI/actions, permissions, notifications, quantity aggregation, Purchase lifecycle derivation, and commercial edit guards.
- Adds auditable cancellation persistence, immutable receival-detail snapshots, and explicit links from compensating inventory transactions to their cancellation source.
- Affects product/location stock buckets, serial status/history, purchase cost replay, inventory reports, and receiving-related reports.
- Requires migration handling that preserves existing receival history and conservative fallback for legacy `BUY` transaction provenance.

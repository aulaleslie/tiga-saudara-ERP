## Why

An approved Purchase receiving can correctly post stock and derive the Purchase as `RECEIVED` or `RECEIVED PARTIALLY`, yet a stale or concurrent generic status request can subsequently overwrite the Purchase header back to `APPROVED`. This leaves lifecycle state inconsistent with approved receiving, inventory transactions, and serial history, as observed for Purchase 19050.

## What Changes

- Make Purchase receiving approval an authoritative atomic lifecycle operation that locks and revalidates the Purchase, receiving note, affected lines, stock, and serial state before applying any mutation.
- Require every receiving submitted for approval to contain at least one row with a strictly positive quantity; zero-quantity rows remain allowed alongside a positive row but do not count as received.
- Derive the Purchase header from cumulative positive quantities on approved receiving notes: `RECEIVED PARTIALLY` while any ordered quantity remains and `RECEIVED` when every line is fulfilled.
- Prevent a Purchase with positive approved receiving evidence from remaining or transitioning back to `APPROVED` through stale status requests, generic edits, or other user-controlled lifecycle actions.
- Restrict generic Purchase lifecycle transitions to valid source states and reserve receiving-derived statuses for receiving and shortfall-completion workflows.
- Make repeated or concurrent approval safe so only one approval can post stock, transactions, serial links, serial history, notifications, and header status.
- Fail approval atomically when required Purchase-detail, stock, serial, location, or receiving relationships are missing or invalid instead of silently skipping evidence.
- Record durable audit evidence for Purchase status transitions caused by receiving approval.
- Add focused regression verification for positive-row eligibility, full and partial status derivation, stale requests, duplicate/concurrent approval, invalid relationships, and rollback. A full application-suite run is not required by this change.

## Capabilities

### New Capabilities

- `purchase-receiving-approval-lifecycle`: Defines atomic Purchase receiving approval, positive-quantity eligibility, authoritative Purchase status derivation, stale-transition protection, idempotency, and approval audit evidence.

### Modified Capabilities

None.

## Impact

- Purchase status endpoints and their authorization/transition validation.
- Purchase receiving approval orchestration in `Modules/Purchase`.
- Generic Purchase editing where stale status or line mutations can race with receiving approval.
- Purchase, Purchase detail, received-note/detail, product-stock, inventory-transaction, serial, notification, and audit persistence boundaries.
- Focused Purchase feature tests; no new external dependency and no intended change to costing, pricing, payment, return, or shortfall-calculation rules.

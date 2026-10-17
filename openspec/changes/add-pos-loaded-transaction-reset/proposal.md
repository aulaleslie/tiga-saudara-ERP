# Proposal

## Why

POS transactions can remain LOADED after their original cart is abandoned, preventing staff from loading them again. Staff currently recover them by changing the database status to DRAFT; a transaction-list action should expose that same recovery without direct database access.

## What Changes

- Add **Kembalikan ke Draf** on LOADED transaction rows.
- Allow users with existing transaction-view and transaction-load permissions to reset a transaction in the active business to DRAFT.
- Preserve the transaction number and persisted contents so the existing Muat action can load it again.
- Reject resets for other statuses and keep recovery independent of an active POS session.
- Keep the agreed status-only scope: no old-cart invalidation, stale-age detection, new permission, approval workflow, or automatic recovery.
- Plan focused verification only; exclude full-suite testing.

## Capabilities

### New Capabilities

- `pos-loaded-transaction-recovery`: Manual LOADED-to-DRAFT recovery from the transaction list, with existing authorization and business scoping.

### Modified Capabilities

None.

## Impact

Changes are confined to POS transaction routes, controller/service handling, the transaction-list Blade/JavaScript, and focused POS feature tests. No schema changes, new dependencies, or changes to checkout, stock, payments, or existing load validation are required.

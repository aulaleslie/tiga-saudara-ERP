# Proposal

## Why

Loading a saved POS draft that contains a non-stock-managed (service) product line makes that line's quantity uneditable. `PosTransactionSnapshotMapper::hydrateCart()` computes `available_qty` as `(int) (... ?? 0)` for every restored line regardless of `stock_managed`, instead of `null` for non-stock lines the way the fresh-add path (`PosCartService::resolveCartProduct()`) already does. The cart's quantity-update guard then treats the reloaded line as having zero stock and rejects any quantity change with "Requested quantity exceeds available stock," even though the same line worked fine before it was saved as a draft. Because any user with `pos.transactions.load` permission can load a draft saved by a different user (no ownership check), this defect surfaces broadly, not just for the cashier who created the draft.

## What Changes

- Fix `PosTransactionSnapshotMapper::hydrateCart()` so `available_qty` is restored as `null` for lines where `stock_managed` is `false`, matching the fresh-add behavior in `PosCartService::resolveCartProduct()`.
- Add a focused regression test covering draft hydration of a mixed cart (one stock-managed line, one non-stock line): assert the non-stock line's `available_qty` is `null` after hydration, and that a subsequent quantity update on that line succeeds.

## Capabilities

### Modified Capabilities
- `pos-draft-stock-management-preservation`: extend the existing "preserve line stock-management behavior" requirement to also cover `available_qty` restoration — a non-stock line's restored `available_qty` must be `null` (not `0`), since a false-zero value re-enables inventory-shortage validation that non-stock lines must remain exempt from.

## Impact

- `Modules/Pos/Services/PosTransactionSnapshotMapper.php` (`hydrateCart()`) — the only code change.
- Behavior restored: quantity edits on non-stock draft-reloaded lines (previously blocked). Remove and price-override are unaffected by this bug (confirmed by code trace: neither path reads `available_qty`).
- Tests: one focused unit/feature test on draft hydration; no full-suite run required, per user direction — run only the targeted test file/filter for verification.

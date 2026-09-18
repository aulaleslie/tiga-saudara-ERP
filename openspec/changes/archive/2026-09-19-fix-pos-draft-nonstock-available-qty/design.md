# Design

## Context

`PosTransactionSnapshotMapper::hydrateCart()` (`Modules/Pos/Services/PosTransactionSnapshotMapper.php`) already resolves each restored line's `stock_managed` classification correctly (line ~219, via `resolveStockManaged()`) — that's the guarantee `pos-draft-stock-management-preservation` already covers. One line below it (~259), the same function computes `available_qty` unconditionally as an int, without branching on the `stock_managed` value it just resolved. The cart's quantity-update guard in `PosCartService::updateLineWithinLock()` treats any non-null `available_qty` as an active stock ceiling, so a reloaded non-stock line ends up with a phantom `available_qty = 0` ceiling that a stock-managed line would never have. The fresh-add path (`PosCartService::resolveCartProduct()`) already returns `null` for non-stock products — the fix is to make the reload path consistent with it.

## Goals / Non-Goals

**Goals:**
- Restore `available_qty = null` for non-stock-managed lines during draft hydration, matching fresh-add behavior.
- Cover the fix with a targeted regression test at the hydration layer.

**Non-Goals:**
- Touching remove-line or price-override logic — confirmed by code trace that neither reads `available_qty`, `stock_managed`, or any field that differs between fresh-add and draft-reload for non-stock lines.
- Changing `SalesLocationResolver` or any stock-managed-line `available_qty` computation — that behavior is correct and unaffected.
- Running the full test suite — a focused test targeting `PosTransactionSnapshotMapper`/draft hydration is sufficient verification for this change, per direction to avoid full-suite planning.

## Decisions

- **Branch on the already-resolved `$stockManaged` variable** at the `available_qty` assignment (~line 259), rather than introducing a new resolution path: `$stockManaged` is computed one line above and is already the source of truth used for the line's `stock_managed` field, so reusing it keeps both fields derived from a single classification instead of two independently-computed ones that can drift apart again.
- **No schema/migration change**: `available_qty` is a transient, session-only cart array field (not persisted to `PosTransactionLine`), so this is a pure in-memory hydration fix.

## Risks / Trade-offs

- [Risk] A caller elsewhere in the cart pipeline assumes `available_qty` is always an int → could throw a type error. Mitigation: `updateLineWithinLock`'s existing guard already treats `available_qty` as nullable (`$line['available_qty'] ?? null`), and the fresh-add path has produced `null` for non-stock lines all along, so no code path can be relying on it always being an int without already being broken for freshly-added non-stock lines today.

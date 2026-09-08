## Context

`Modules/Pos/Resources/views/sell.blade.php` computes cart button state on every `renderCart(snapshot)` call (invoked after every cart mutation and every draft load/save response). Today it derives one flag, `allSerialsValid`, by checking parent-line and bundle-component serial counts against required quantities (~lines 1554-1591), and ANDs that flag into both `canSaveDraft` and `canCheckout` (~lines 1593-1594).

Backend enforcement is already split correctly:
- `PosTransactionService::saveAndNewWithinLock` (draft save) has no serial-count check.
- `FinalizePosCheckoutService::validateSerialAssignments`, called from `validateCartFulfillability` inside both `preflight()` and `finalize()`, is the sole authoritative exact-match gate (`count($assigned) !== $qty` throws `SERIAL_INVALID`).

So the backend contract does not need to change; only the client button-gating logic needs to stop over-constraining Save Draft.

## Goals / Non-Goals

**Goals:**
- Save Draft is enabled regardless of serial completeness/mismatch, as long as other existing non-serial conditions hold (items present, grand total > 0, customer resolved, prices valid).
- Checkout continues to require exact serial match per serial-required parent line and per serial-required bundle component, unchanged from current behavior.
- Cashier still sees a clear indicator when serials are incomplete/mismatched, but it no longer blocks saving as draft.

**Non-Goals:**
- No backend/service changes — `saveAndNewWithinLock` and `validateSerialAssignments` remain as-is.
- No change to over-assignment vs under-assignment handling — both are treated the same (allowed on draft, blocked at checkout).
- No change to bundle-component serial entry/draft persistence mechanics (already supports partial serials).
- No change to `serial_status` computation in `PosCartService::getSnapshot` (already correctly computed as `'ok'`/`'incomplete'`).

## Decisions

- **Decouple `canSaveDraft` from `allSerialsValid`**: remove `allSerialsValid` from the `canSaveDraft` expression at sell.blade.php:1593. Keep it in `canCheckout` (line 1594) so checkout gating is unaffected.
  - Alternative considered: introduce a separate `hasSerialMismatch` flag used only for messaging, and keep `allSerialsValid` conceptually meaning "checkout-ready." Rejected as unnecessary — the existing `allSerialsValid` variable already means exactly "checkout-ready serial state" once decoupled from `canSaveDraft`; no rename needed, just remove it from one condition.
- **Status messaging**: keep using the existing `mismatchMessage` string, but only treat it as a blocking/danger-styled message when it's the reason Checkout (not Save Draft) is unavailable. When items are otherwise saveable as a draft, downgrade the mismatch line to informational styling (same pattern already used for the "kasir tanpa terminal" and "izin pos.checkout.payment" muted messages at lines 1607-1610), so the cashier still sees what's incomplete without it reading as an error blocking their save.
- **No new snapshot fields**: reuse existing `line.assigned_serials`, `line.qty`, `line.bundle_item_serials` / `item.assigned_serials`, and `line.serial_status` already present in the snapshot payload.

## Risks / Trade-offs

- [Risk] Cashier saves a draft with incomplete serials, forgets to finish scanning, and is surprised at checkout when the button is disabled with no prior context. → Mitigation: keep the informational mismatch message visible whenever a mismatch exists, even on the draft-saveable path, so it's visible before the cashier reaches checkout.
- [Risk] Some other code path assumes `canSaveDraft === canCheckout` conditions are equivalent (e.g. a shared helper or duplicated inline check elsewhere in the same file). → Mitigation: grep the file for other `allSerialsValid`/`canSaveDraft` usages before editing to confirm this is the single source of truth (already confirmed via prior exploration — button disabled-state assignment is the only consumer).

## Migration Plan

Single-file client-side change, no data migration. Deploy as a normal code change; no rollback complexity beyond reverting the file. Update `openspec/specs/pos-serial-qty-mismatch-validation/spec.md` in the same change so spec and code move together.

## Open Questions

None outstanding — over-assignment handling was clarified during exploration (any mismatch is allowed on draft; only exact match gates checkout).

## Why

Cashiers currently cannot save a POS transaction as a draft unless every serial-required line already has its serial count exactly matching the entered quantity. In practice serial scanning often can't be finished in one pass (stock is spread across shelves, customer is waiting, item is being fetched), so cashiers are blocked from parking the sale as a draft even though the backend already tolerates incomplete serials on save. Serial completeness should only be required at final checkout, not at draft save time.

## What Changes

- Client-side POS cart (`Modules/Pos/Resources/views/sell.blade.php`) no longer includes the serial-match check (`allSerialsValid`) in the condition that enables/disables the **Save Draft** button. Save Draft becomes gated only by existing non-serial conditions (has items, grand total > 0, customer resolved, prices valid).
- **Checkout** button gating keeps requiring exact serial match (`assigned_serials.count === qty` for parent lines, and matching required quantity for bundle components) — unchanged from today.
- Any mismatch direction (under-assigned or over-assigned serials) is allowed on Save Draft; only Checkout enforces the exact match. No new "over-assignment blocks draft" special case is introduced.
- Cart status messaging is adjusted so an incomplete/mismatched serial state shown while items are otherwise saveable is presented as informational (not a hard blocker) when Save Draft is available, while still fully blocking Checkout with the existing mismatch message.

## Capabilities

### New Capabilities
(none)

### Modified Capabilities
- `pos-serial-qty-mismatch-validation`: The requirement "Save Draft button MUST be disabled" on serial/qty mismatch is removed. Save Draft is no longer gated by serial completeness. Checkout continues to require exact serial match per line (parent and bundle components).

## Impact

- **Frontend**: `Modules/Pos/Resources/views/sell.blade.php` (`renderCart` button-state block, ~lines 1554-1611) — decouple `canSaveDraft` from `allSerialsValid`; keep `canCheckout` requiring it.
- **Backend**: No changes required. `Modules/Pos/Services/PosTransactionService.php::saveAndNewWithinLock` already has no serial-count enforcement. `Modules/Pos/Services/FinalizePosCheckoutService.php::validateSerialAssignments` (called from `validateCartFulfillability` in both `preflight` and `finalize`) already strictly enforces exact match at checkout and remains the sole enforcement point.
- **Spec**: `openspec/specs/pos-serial-qty-mismatch-validation/spec.md` requirements updated to reflect Save Draft no longer being blocked by serial mismatch.
- No database, migration, or API contract changes.

## Why

Two POS defects surfaced in production. On printed receipts, each line's total column is shown 100× too small (a struk for a Rp 335.000 line prints "3.350") while the grand total and the per-unit breakdown print correctly, undermining trust in the receipt. Separately, when a non-privileged cashier requests a unit-price override on a cart line, that line's quantity "reduce" (−) control incorrectly flips into the "Periksa" approval state, blocking a normal quantity change that was never requested.

## What Changes

- Fix the receipt line subtotal so it renders `line_meta['line_total']` as Rupiah instead of dividing by 100. The value is already normalized to Rupiah when the cart snapshot is built and persisted; the receipt's stale `/100` (a leftover from when only PACKED lines stored a cents value) must be removed for both completed-checkout and draft/loaded-transaction receipt paths.
- Preserve the packed unit-breakdown price scaling (`box_price_applied` / `loose_price_applied` remain in cents and keep their `/100`) so packed lines still print correct per-unit Rupiah values.
- Fix the cart quantity control so a pending/approved PRICE_OVERRIDE request no longer leaks into the quantity-reduce approval slot. The client-side pending-approval store must be scoped by action type (or defer to the server snapshot's already action-typed `pending_approvals`) so only a QTY_REDUCE request drives the − / "Periksa" button state.

## Capabilities

### New Capabilities
<!-- None: both fixes correct existing spec-level behavior. -->

### Modified Capabilities
- `pos-receipt`: Line total (sub_total) printed on the receipt MUST equal the Rupiah line total, not one-hundredth of it, across completed, draft, and loaded-transaction receipts.
- `pos-supervised-cart-actions`: A line's quantity-reduce approval control MUST reflect only QTY_REDUCE requests for that line; a PRICE_OVERRIDE (or other non-quantity) request MUST NOT change the quantity − / "Periksa" button state.

## Impact

- Code:
  - `Modules/Pos/Services/PosReceiptService.php` — remove `/100` on `line_meta['line_total']` in `getReceiptData()` and `getTransactionReceiptData()`; keep `buildPackedUnitBreakdown()` scaling unchanged.
  - `Modules/Pos/Resources/views/sell.blade.php` — scope `clientPendingApprovals` by action type (or drop the generic client fallback for the qty-reduce slot) so price-override state does not render on the quantity control.
- No database, migration, or API contract changes. No changes to totals math (grand total already correct).
- Tests: POS receipt rendering tests and supervised cart-action / approval rendering tests.

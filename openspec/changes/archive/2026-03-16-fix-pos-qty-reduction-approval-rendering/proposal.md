## Why

Non-privileged quantity reduction requests are being created successfully, but the follow-up `Periksa Persetujuan` control does not reliably render after submission and can disappear after reload. This breaks the supervised action flow because users cannot continue checking or executing approved requests from the cart UI.

## What Changes

- Fix POS sell cart rendering so quantity-reduction approval state is resolved consistently from both immediate client state and server `pending_approvals` snapshot data.
- Ensure cart refresh after approval request submission uses the correct response contract (`cart_snapshot`) before re-rendering rows.
- Align quantity-reduction approval rendering behavior with existing delete/clear approval patterns for pending, approved, rejected, and cancelled states.
- Add regression coverage for quantity-reduction approval visibility right after request submission and after full cart reload.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-supervised-cart-actions`: quantity-reduction approval UI state must remain visible and actionable across submit, re-render, and refresh cycles using deterministic approval-state mapping.

## Impact

- Affected UI: `Modules/Pos/Resources/views/sell.blade.php` (cart row rendering and approval-state handling for quantity reduction).
- Affected API contract usage: POS cart show response handling in frontend (`/pos/sell/cart` response parsing).
- Affected tests: POS approval workflow feature coverage for quantity reduction state persistence/rendering.

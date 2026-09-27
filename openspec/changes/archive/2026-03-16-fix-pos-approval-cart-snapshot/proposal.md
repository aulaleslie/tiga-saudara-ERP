## Why

After a user submits a quantity-reduction approval request ("Kirim Permintaan"), the `Periksa Persetujuan` button should render immediately to let them check approval status. Currently, it doesn't render because the cart is never re-rendered after the approval request succeeds. The approval request endpoint returns only `{request_id, status}` but the frontend expects `{request_id, status, cart_snapshot}` to trigger a full cart refresh that calls `buildLineRow()` for each line, which then evaluates approval state and renders the pending button.

## What Changes

- Add `cart_snapshot` to the POST `/pos/sell/approval-requests` response in `PosCartApprovalController::store()`
- Frontend will now receive the updated cart snapshot after approval request submission, triggering `renderCart()` which calls `buildLineRow()` for each line, ensuring all approval controls render correctly
- No breaking changes; backward-compatible response expansion (existing `request_id` and `status` fields remain)

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-supervised-cart-actions`: The approval request endpoint MUST return a `cart_snapshot` contract matching other cart mutation endpoints so that quantity-reduction approval state can be deterministically rendered immediately after request submission.

## Impact

- **Affected endpoint**: POST `/pos/sell/approval-requests` (response contract change)
- **Affected controller**: `Modules/Pos/Http/Controllers/PosCartApprovalController::store()`
- **Affected service injection**: Requires `PosCartService` dependency in `PosCartApprovalController`
- **Affected frontend**: `Modules/Pos/Resources/views/sell.blade.php` (already expects `cart_snapshot`, no changes needed)
- **Affected tests**: POS approval workflow feature coverage should verify `cart_snapshot` is present in response

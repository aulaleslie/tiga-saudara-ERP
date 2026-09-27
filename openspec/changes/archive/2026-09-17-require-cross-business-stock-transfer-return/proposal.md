## Why

The current stock transfer policy lets transfers between two non-PKP businesses complete without a return. The business rule now requires every transfer between distinct businesses to return its full received quantity, regardless of either business's PKP status.

## What Changes

- **BREAKING** For transfers approved after this change, require a full return whenever origin and destination locations belong to different businesses, including non-PKP to non-PKP routes.
- Continue to require no return for transfers between locations of the same business, regardless of PKP status.
- Keep destination tax classification based on the existing route policy; PKP status no longer determines whether a cross-business return is required.
- Preserve the immutable policy and lifecycle of transfers approved before the change. Draft or pending transfers approved afterward use the new rule.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `stock-transfer-route-policy`: Replace the PKP-dependent mandatory-return matrix with a business-identity rule for newly approved transfers.
- `stock-transfer-cross-tenant-tax-return`: Require full-product obligations and `AWAITING_RETURN` after exact receipt for every newly approved cross-business transfer, including non-PKP to non-PKP.

## Impact

- Changes route-policy resolution in `Modules/Adjustment/Services/TransferRoutePolicyResolver.php`; approval snapshots remain in `TransferLifecycleService`.
- Existing forward receipt and return dispatch/receipt services consume the persisted `mandatory_return` decision and should be verified for the newly mandatory non-PKP to non-PKP route.
- Update focused route-policy and end-to-end transfer tests, plus affected OpenSpec requirements. No schema migration or historical data rewrite is expected.

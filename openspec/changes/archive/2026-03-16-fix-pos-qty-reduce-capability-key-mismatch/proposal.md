## Why

Non-privileged cashiers can submit quantity-reduction approval requests, but the follow-up `Periksa Persetujuan` control may not render even when request status is `PENDING`. The current frontend checks a missing capability key (`can_reduce_quantity`) and fails open to privileged behavior, which bypasses the non-privileged approval UI path.

## What Changes

- Define a canonical `can_reduce_quantity` capability flag in POS role capability payloads, aligned with `pos.cart.line.reduce` permission checks.
- Harden POS sell frontend capability resolution so missing or partial payloads default to restrictive behavior (non-privileged) instead of privileged behavior.
- Ensure quantity-reduction pending/approved controls are rendered from the non-privileged branch whenever direct reduce permission is absent.
- Add regression coverage for capability payload contract and non-privileged quantity-approval rendering behavior.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-supervised-cart-actions`: quantity-reduction approval controls MUST render for users without direct reduce permission even when capability payload shape is partial or evolving.

## Impact

- Affected backend capability contract: `Modules/Pos/Services/PosRolePolicyService.php`.
- Affected frontend capability consumer and row rendering branch selection: `Modules/Pos/Resources/views/sell.blade.php`.
- Affected tests: POS role/capability and supervised-cart approval regression coverage.

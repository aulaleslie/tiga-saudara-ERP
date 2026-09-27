## Why

When reducing cart line quantity for products with serial numbers, the current system clears all assigned serials, forcing users to re-enter them. Additionally, Super Admin users are blocked from reducing quantity without approval despite their unrestricted role. These issues reduce usability and contradict existing role-based authorization principles.

## What Changes

- **Backend**: Stop clearing assigned serials when quantity decreases. Instead, preserve serials and let validation enforce the mismatch at save time.
- **Backend**: Add Super Admin bypass in quantity-reduction authorization so Super Admins can reduce quantity without approval tokens.
- **Frontend**: Enhance user visibility of serial-quantity mismatches with clear error messaging blocking save/checkout operations.
- **Validation**: Strengthen the frontend guard to prevent checkout when assigned serial count ≠ quantity for serial-required lines.

## Capabilities

### New Capabilities

- `pos-serial-qty-mismatch-validation`: When users reduce quantity on serial-required items, preserve assigned serials and block save operations until the count matches the new quantity or serials are manually adjusted.
- `pos-super-admin-cart-action-bypass`: Super Admin users can execute cart mutations (qty reduction, line removal, cart clear) without requiring supervisory approval.

### Modified Capabilities

- `pos-supervised-cart-actions`: Extend approval bypass to include Super Admin detection in quantity-reduction authorization flow.

## Impact

**Affected Code**:
- `Modules/Pos/Services/PosCartService.php` - Remove serial clearing on qty decrease
- `Modules/Pos/Services/PosCartActionAuthorizationService.php` - Add Super Admin bypass
- `Modules/Pos/Resources/views/sell.blade.php` - Enhanced mismatch warning visibility (may need refinement)

**Affected APIs**:
- PUT/PATCH `/pos/sell/cart/lines/{lineId}` - No schema change, but behavior change (serials preserved)

**Dependencies**:
- Uses existing `pos-supervised-cart-actions` approval infrastructure
- Uses existing serial validation in `pos-checkout-serial-stock-validation`

**Breaking Changes**: None. Behavior only becomes more lenient (serials preserved instead of cleared).

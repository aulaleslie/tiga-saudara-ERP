## Why

POS cashiers with limited privileges need a controlled way to reduce item quantities in the cart without having direct access to quantity decrements. Currently, all users can freely edit quantities with the same input field, which bypasses audit trails and approval workflows for quantity reductions. This change implements a dual-input system that restricts non-privileged users to only increments and manual inputs greater than or equal to current quantity, while providing an explicit "Reduce" button that triggers an approval workflow—ensuring all quantity reductions are intentional, auditable, and require authorization.

## What Changes

- **Quantity Input Behavior**: Non-privileged users see a read-only or increment-only quantity field; privileged users retain current full control (increment, decrement, free input).
- **Reduce Button**: Non-privileged users get a small "Reduce" button (↓ icon) next to the quantity field that opens a modal.
- **Reduction Modal**: Modal captures the desired quantity (validated: 1 ≤ new_qty < current_qty) and an optional reason for the reduction.
- **Approval Integration**: Reduction requests from non-privileged users flow through the existing ApprovalManager workflow (consistent with current LINE_REMOVE and CART_CLEAR patterns).
- **Validation**: Quantity input validation prevents non-privileged users from directly entering values lower than current quantity; only the modal allows reductions.
- **UI Rendering**: Cart line rendering is now conditional on user privilege—showing different controls based on `can_reduce_quantity` capability.

## Capabilities

### New Capabilities

- `pos-quantity-reduction-modal`: Provides non-privileged users with a dedicated modal to request quantity reductions, capturing reason and new quantity, with validation and approval workflow integration.
- `pos-privilege-based-quantity-controls`: Implements privilege-based dual-input system for cart quantity management—read-only or increment-only inputs for non-privileged users vs. full controls for privileged users.

### Modified Capabilities

- `pos-cart-line-management`: Extends existing cart line management with role-based quantity control rendering and validation logic to enforce reduction-only-via-modal for non-privileged users.

## Impact

- **Code**: Modifications to `/Modules/Pos/Resources/views/sell.blade.php` (cart rendering and event handlers), quantity validation logic.
- **Frontend**: New modal component for quantity reduction, conditional rendering of quantity controls based on privilege check.
- **APIs**: Existing `/pos/sell/cart/lines/{id}` PATCH endpoint reused; approval workflow unchanged.
- **Backend**: Cart line controller may need privilege checks during quantity update (if not already present).
- **Audit**: All quantity reductions now go through ApprovalManager, creating audit trail entries.
- **Dependencies**: None new; uses existing ApprovalManager, SweetAlert2, and privilege capability system.

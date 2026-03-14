## 1. Prepare Frontend Infrastructure

- [x] 1.1 Add privilege flag check to cart rendering context (ensure `canReduceQuantity` is available from roleCapabilities)
- [x] 1.2 Add reduce-quantity modal HTML skeleton to sell.blade.php (hidden by default)
- [x] 1.3 Create helper CSS classes for reduce button styling (compact size, ↓ icon)

## 2. Implement Quantity Control Rendering (Pattern 1: Dual Input)

- [x] 2.1 Modify `renderLineRow()` function to conditionally render quantity controls based on `canReduceQuantity` privilege
- [x] 2.2 For privileged users: Keep existing quantity input with full increment/decrement control
- [x] 2.3 For non-privileged users on non-serial lines: Render quantity input + Reduce button (↓)
- [x] 2.4 For non-privileged users on serial lines: Render existing serial UI + Reduce button alongside quantity
- [x] 2.5 Ensure both rendering paths maintain proper styling and layout (no clutter)

## 3. Implement Quantity Input Validation

- [x] 3.1 Update `cartBody.addEventListener('change', ...)` handler to check `canReduceQuantity` flag
- [x] 3.2 For non-privileged users: If newQty < prevQty, revert input to prevQty and show error "Use the Reduce button to decrease quantity"
- [x] 3.3 For non-privileged users: If newQty >= prevQty, apply change immediately (no approval needed)
- [x] 3.4 For privileged users: Keep existing behavior (qty < prevQty triggers ApprovalManager, qty >= prevQty applies immediately)
- [ ] 3.5 Test validation logic across all user privilege scenarios

## 4. Implement Reduce Button & Modal

- [x] 4.1 Add click event handler for `.js-reduce-qty` button (or similar class) in cartBody delegation
- [x] 4.2 On reduce button click: Capture current quantity, line ID, and open reduction modal
- [x] 4.3 Populate modal fields:
  - [x] 4.3.1 Display "Current Qty: X" (read-only)
  - [x] 4.3.2 Render "New Qty" input with type="number", min="1", max="X-1"
  - [x] 4.3.3 Render "Reason" textarea (optional, no placeholder or empty default)
- [x] 4.4 Add modal validation logic:
  - [x] 4.4.1 New Qty must be >= 1 and <= (current - 1)
  - [x] 4.4.2 Show/hide validation error message dynamically as user types
  - [x] 4.4.3 Disable "Request Reduction" button until valid input is entered

## 5. Integrate Reduction Modal with ApprovalManager

- [x] 5.1 On "Request Reduction" button submit in modal:
  - [x] 5.1.1 Capture new quantity and reason from modal fields
  - [x] 5.1.2 Close modal
  - [x] 5.1.3 Call `ApprovalManager.wrapAction()` with:
    - action_type: 'QTY_REDUCE'
    - target_type: 'pos_cart_line'
    - target_id: lineId
    - payload: { qty: newQty, reason: reason || null }
- [x] 5.2 Handle ApprovalManager response:
  - [x] 5.2.1 If approval_required: Show "Reduction request submitted. Awaiting approval."
  - [x] 5.2.2 If approved: Update cart quantity and show "Reduction approved and applied"
  - [x] 5.2.3 If rejected: Show rejection reason and revert any changes
- [x] 5.3 On modal "Cancel" button: Close modal and reset cart status

## 6. Backend Validation (Optional but Recommended)

- [x] 6.1 Ensure cart line PATCH controller validates privilege-level constraints
  - [x] 6.1.1 If user lacks `can_reduce_quantity` AND qty_new < qty_current: Reject with APPROVAL_REQUIRED
  - [x] 6.1.2 If user lacks `can_reduce_quantity` AND qty_new >= qty_current: Allow immediate application
- [x] 6.2 Document backend privilege check logic for future maintainers

## 7. Testing & Validation

- [ ] 7.1 Manual testing: Non-privileged user views cart → Sees quantity input + Reduce button
- [ ] 7.2 Manual testing: Non-privileged user attempts direct qty decrease → Input reverts, error shown
- [ ] 7.3 Manual testing: Non-privileged user increases qty via input → Change applied immediately
- [ ] 7.4 Manual testing: Non-privileged user clicks Reduce button → Modal opens with correct max qty
- [ ] 7.5 Manual testing: Non-privileged user submits invalid qty in modal → Validation error shown
- [ ] 7.6 Manual testing: Non-privileged user submits valid reduction → Approval request sent
- [ ] 7.7 Manual testing: Privileged user views cart → Sees quantity input (no Reduce button)
- [ ] 7.8 Manual testing: Privileged user decreases qty → ApprovalManager triggered (existing behavior)
- [ ] 7.9 Test serial line rendering: Ensure reduce button appears with serial controls (no layout break)
- [ ] 7.10 Test modal keyboard UX: Escape closes modal, Tab focuses fields, Enter submits (if valid)
- [ ] 7.11 Test approval workflow: Pending status, approval, rejection flow all work as expected
- [ ] 7.12 Browser testing: Responsive design on mobile (ensure button and modal are usable)

## 8. Documentation & Cleanup

- [x] 8.1 Add inline code comments explaining privilege check logic in sell.blade.php
- [x] 8.2 Update POS module README (if exists) with note on privilege-based quantity controls
- [ ] 8.3 Verify no console errors or warnings in browser DevTools
- [ ] 8.4 Run final code review: Ensure style consistency and no leftover debug code
- [ ] 8.5 Create git commit with summary of changes

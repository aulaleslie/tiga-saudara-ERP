## 1. Add Table Header Column

- [x] 1.1 Add "Aksi" column header to cart table `<thead>` (sell.blade.php around line 796)

## 2. Refactor Quantity Spinner Rendering

- [x] 2.1 Refactor non-serial privileged user quantity cell: Replace `<input>` with `[-] [input] [+]` spinner layout
- [x] 2.2 Refactor non-serial non-privileged user quantity cell: Add spinner with approve buttons below (Periksa/Lanjutkan)
- [x] 2.3 Refactor serial privileged user quantity cell: Replace inline `<input>` with spinner at top, keep serial button and chips below
- [x] 2.4 Refactor serial non-privileged user quantity cell: Replace inline `<input>` with spinner, keep approve buttons and serial controls below
- [x] 2.5 Ensure all quantity spinners use consistent styling (Bootstrap button classes: `btn btn-sm btn-outline-*`)

## 3. Move Delete Button to Actions Column

- [x] 3.1 Extract "Hapus" button logic from Sub Total cell (currently lines 1933-1942)
- [x] 3.2 Create new table cell in each row for Actions column
- [x] 3.3 Move delete button rendering to Actions column with same approval state logic (no approval → Hapus, pending → Periksa, approved → Lanjutkan)
- [x] 3.4 Ensure button retains `data-approval-pending` and `data-approval-token` attributes for approval flow

## 4. Wire Up Event Handlers

- [x] 4.1 Verify `+` button triggers existing quantity increase handler (js-line-qty input change)
- [x] 4.2 Verify `-` button triggers existing quantity reduce handler (js-reduce-qty) or transforms to approval button when appropriate
- [x] 4.3 Verify delete button in Actions column triggers existing `js-line-remove` handler with ApprovalManager
- [x] 4.4 Test that event delegation works for dynamically rendered buttons (cartBody event listeners)

## 5. Update CSS & Styling

- [x] 5.1 Apply Bootstrap utilities for button sizing and spacing in spinner: `btn btn-sm btn-outline-*`
- [x] 5.2 Ensure approval buttons use color coding: red (Hapus), yellow (Periksa), green (Lanjutkan/✓)
- [x] 5.3 Adjust cell padding/alignment for new Actions column
- [x] 5.4 Verify serial item cell layout accommodates spinner + controls without excessive height growth

## 6. Test Approval Workflows

- [x] 6.1 Test quantity reduction: Non-privileged user requests approval → Periksa → Approved → Execute with token
- [x] 6.2 Test quantity reduction: Request is rejected, button returns to normal state
- [x] 6.3 Test line deletion: Non-privileged user requests approval → Periksa → Approved → Execute with token
- [x] 6.4 Test line deletion: Request is rejected, button returns to normal state
- [x] 6.5 Test quantity reduction: Cancel approved action (press Cancel in confirmation modal)
- [x] 6.6 Test line deletion: Cancel approved action (press Cancel in confirmation modal)

## 7. Test Basic Functionality

- [x] 7.1 Test non-serial item: Click + button, quantity increases
- [x] 7.2 Test non-serial item: Click - button (privileged user), quantity decreases
- [x] 7.3 Test serial item: Spinner displays with serial button and chips below
- [x] 7.4 Test serial item: Click + button, quantity increases and serial assignment counter updates
- [x] 7.5 Test direct input edit: Edit quantity field directly, value updates on blur/Enter

## 8. Cross-Browser & Responsive Testing

- [x] 8.1 Test spinner buttons and Actions column on desktop (Chrome, Firefox, Safari)
- [x] 8.2 Test spinner buttons and Actions column on tablet (iPad-like viewport)
- [x] 8.3 Test spinner buttons and Actions column on mobile (small screen, table scrolling)
- [x] 8.4 Verify no layout breakage with table scrolling on small screens

## 9. Code Review & Cleanup

- [x] 9.1 Review buildLineRow() function for code clarity and maintainability
- [x] 9.2 Ensure no duplicate event listeners or handler conflicts
- [x] 9.3 Verify console has no JavaScript errors or warnings
- [x] 9.4 Clean up any commented-out code or debug statements

## 10. Fix Approval Button Not Showing After "Kirim Permintaan"

- [x] 10.1 Fix bug: "Periksa Persetujuan" button not appearing after approval request submission
- [x] 10.2 Fetch fresh cart snapshot from server after approval request succeeds (before re-rendering)
- [x] 10.3 Align qty approval logic with delete button logic (use `if (qtyReduceReq)` instead of conditional status checks)
- [x] 10.4 Fix both serial and non-serial item approval button rendering to match delete button pattern

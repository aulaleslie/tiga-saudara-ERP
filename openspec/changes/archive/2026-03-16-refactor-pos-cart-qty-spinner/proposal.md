## Why

The current POS cart quantity input is a plain text field, which doesn't clearly indicate that quantity can only be increased or requires approval for reduction. Moving the delete button below the subtotal creates visual confusion about what the button controls. A spinner-style quantity control (with +/- buttons) is more intuitive and aligns with e-commerce UX patterns. Consolidating delete actions in a dedicated column improves scanability and separates quantity concerns from line item deletion.

## What Changes

- **Quantity input** replaced with spinner design: `[-] input [+]` with styled buttons
- **Delete button ("Hapus")** moved from Sub Total cell to new dedicated "Aksi" (Actions) column
- **Quantity reduction approval flow** updated: minus button transforms to "Periksa Persetujuan" when approval is pending/approved
- **Cart table headers** expanded: `Produk | Harga | Qty | Sub Total | Aksi`
- **Serial items** retain complex layout but quantity spinner replaces inline input
- **Approval state machine** preserved: both QTY_REDUCE and LINE_REMOVE approvals remain unchanged in behavior

## Capabilities

### New Capabilities
- `pos-cart-qty-spinner`: Quantity input rendered as spinner with +/- buttons, supporting increment/decrement with approval workflow for reduction
- `pos-cart-actions-column`: Dedicated Actions column displaying line deletion controls with approval state management

### Modified Capabilities
- `pos-cart-approval-workflow`: Quantity reduction approval now uses minus button transformation (no new button below), delete approval flow consolidated in Actions column

## Impact

- **Files Modified**: `Modules/Pos/Resources/views/sell.blade.php` (JavaScript rendering logic for cart rows)
- **Behavior**: Quantity and delete actions now have clearer visual separation and improved UX
- **Backward Compatible**: Approval system logic unchanged—button state management and backend flow identical
- **No API Changes**: Existing approval request/response structures remain the same

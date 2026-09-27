## Why

Supervisors currently have difficulty accessing the POS approval queue because the link is only available within the POS Sell screen. This screen is protected by middleware that requires an active POS session, which supervisors typically do not have. This creates a circular dependency where a supervisor cannot access the queue to approve requests unless they also open a cashier session.

## What Changes

- Add a new menu item "Antrian Persetujuan" to the main sidebar within the POS dropdown menu.
- Ensure the menu item is visible to users with the `pos.supervisor.approval` permission.
- Ensure the menu item points directly to the approval queue index without requiring an active POS session.

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `pos-supervised-cart-actions`: Update requirements to ensure the supervisor queue is accessible from the main dashboard navigation, independent of active POS sessions.

## Impact

- **UI**: `resources/views/layouts/menu.blade.php` will be updated to include the new link.
- **UX**: Supervisors can resolve pending requests directly from the sidebar.

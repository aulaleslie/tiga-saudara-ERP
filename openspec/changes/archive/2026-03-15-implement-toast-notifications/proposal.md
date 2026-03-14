## Why

The POS approval queue currently uses native browser `alert()` dialogs for success and error feedback, which creates a poor user experience. These Windows-style alerts clash with the rest of the application's Bootstrap/modern UI design and break the visual consistency that users expect. A proper toast notification system will provide feedback that's consistent, accessible, and non-disruptive.

## What Changes

- Add a reusable `showToast()` helper function to the global application JavaScript
- Replace three native `alert()` calls in the approval queue with SweetAlert toast notifications
- Toast notifications will auto-close after 2-3 seconds (no user interaction required)
- Both success and error messages will use the same toast system

## Capabilities

### New Capabilities
- `toast-notification-system`: A global JavaScript helper function that displays auto-closing toast notifications using SweetAlert's built-in toast mode. Supports success, error, warning, and info toast types.

### Modified Capabilities

## Impact

- **Code affected**: `Modules/Pos/Resources/views/approval-queue/index.blade.php`
- **Dependencies**: SweetAlert2 (already included in the app)
- **Files created**: Will add a toast helper to the global JavaScript initialization
- **Files modified**: approval-queue index.blade.php (replace 3 alert() calls)

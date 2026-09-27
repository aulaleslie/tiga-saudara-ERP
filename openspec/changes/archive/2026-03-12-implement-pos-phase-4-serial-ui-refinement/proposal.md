## Why

The current POS serial handling relies on browser `prompt()` for manual and scanner input, which is disruptive to the user experience and lacks proper validation feedback during input. It also doesn't support efficient scanner bursts or modern mobile browser workflows. Refining the UI to use an in-app modal will provide a smoother, more controllable, and professional interface for cashiers.

## What Changes

1.  **Replace `prompt()` with Modal**: All serial entry points that previously used `window.prompt()` will now open a custom Vue/JavaScript modal.
2.  **Integrated Serial Controls**: Serial management will be more tightly integrated into the line-item quantity area, making it clear which serials belong to which item.
3.  **Refined Chip Layout**: Serial "chips" (tags representing added serials) will be better aligned and optimized for both desktop and mobile viewports.
4.  **Scanner Optimization**: The modal will auto-focus on input and support continuous scanning (EAN/UPC/Serial) without closing after each entry.

## Capabilities

### New Capabilities
- `pos-serial-modal-input`: In-app modal for capturing serial numbers with real-time feedback and scanner support.
- `pos-serial-scanner-burst`: Support for high-speed scanning where multiple serials can be added sequentially without manual modal interaction.

### Modified Capabilities
- `pos-cart-line-serial-management`: Requirements for displaying and removing serials from cart lines are refined for better UX and alignment.

## Impact

- **Frontend**: `Modules/Pos/Resources/views/sell.blade.php` will be heavily modified to implement the modal and new chip layout.
- **JavaScript**: New JS logic for modal state management and keyboard listeners for scanner events.
- **Compatibility**: Existing backend serial APIs (`/append`, `/remove`) remain unchanged.

## Why

The POS "Kosongkan Keranjang" (Clear Cart) action and other administrative tasks currently rely on browser-native dialogs (`window.confirm` and `window.prompt`). These dialogs:
1.  Feel non-premium and disjointed from the application's visual style.
2.  Provide a poor mobile/tablet experience.
3.  Are often perceived as "system errors" by non-technical users.

Additionally, the "Kosongkan Keranjang" button is currently always clickable, even when the cart and customer selection are empty, leading to unnecessary user interactions and potential server requests.

## What Changes

- **Dialog Replacement**: Replace all instances of `confirm()` and `prompt()` in the POS module with SweetAlert2 modals.
- **Approval Workflow Enhancement**: Update the `ApprovalManager` in `sell.blade.php` to use a visual modal for both confirmation and reason input.
- **Button State Logic**: Implement conditional enabling/disabling for the "Kosongkan Keranjang" button. It will only be active if:
    - The cart contains at least one item.
    - OR a customer has been explicitly selected.
- **Cross-Module Cleanup**: Scan and replace native dialogs in Terminal Management and Transaction History views.

## Impact

- `Modules/Pos/Resources/views/sell.blade.php`: Script update for `ApprovalManager` and `renderCart`.
- `Modules/Pos/Resources/views/terminals/index.blade.php`: Update form submission logic.
- `Modules/Pos/Resources/views/transactions/index.blade.php`: Update cancel button logic.
- `Modules/Pos/Resources/views/transactions/show.blade.php`: Update cancel button logic.

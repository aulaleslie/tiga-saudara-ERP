## 1. Frontend Scaffolding

- [x] 1.1 Add `#pos-serial-modal` Bootstrap modal structure to `Modules/Pos/Resources/views/sell.blade.php`.
- [x] 1.2 Include modal input field with `id="pos-serial-modal-input"` and status area `#pos-serial-modal-status`.

## 2. Interaction Logic

- [x] 2.1 Update `.js-serial-add` click handler to open the modal instead of calling `prompt()`.
- [x] 2.2 Implement `shown.bs.modal` listener to auto-focus the serial input field.
- [x] 2.3 Implement `keydown` listener on `#pos-serial-modal-input` to handle Enter key (scanner/manual submission).
- [x] 2.4 Update submission logic to clear input and re-focus on success, providing transient feedback in `#pos-serial-modal-status`.

## 3. UI Refinement

- [x] 3.1 Refactor serial chip rendering in `renderCart()` to use a more compact, flex-wrap layout.
- [x] 3.2 Improve vertical alignment of serial chips relative to the line item quantity area.

## 4. Verification

- [x] 4.1 Manually verify modal auto-focus on "+ Serial" click.
- [x] 4.2 Verify scanner burst behavior (multiple successful scans without closing modal).
- [x] 4.3 Run `POSSerialValidationCheckoutTest.php` and `POSSerialIncrementalAssignmentTest.php` to ensure no regressions.

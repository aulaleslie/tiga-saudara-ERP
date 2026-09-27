## 1. Serial Action Control Visibility

- [x] 1.1 Update serial-required row rendering in `Modules/Pos/Resources/views/sell.blade.php` so the `.js-serial-add` button uses Bootstrap Icons-compatible markup and/or visible text fallback instead of Font Awesome-only icon markup.
- [x] 1.2 Ensure the serial action control remains compact but clearly visible across cart row responsive states and retains the existing click target semantics.

## 2. Serial Modal Context and Remove Behavior

- [x] 2.1 Fix serial modal open context lookup so product name is sourced from a stable row contract (aligned selector or explicit data attribute) and always matches the clicked line.
- [x] 2.2 Refactor serial delete flow into a shared helper that accepts `lineId` and `serialNumber`, then reuses it for cart-chip and modal-chip remove actions.
- [x] 2.3 Add modal-scoped click handling for `.js-serial-remove` using `currentSerialLineId` so removal works inside the modal without requiring `tr[data-line-id]`.

## 3. Regression Validation

- [x] 3.1 Add or update targeted POS serial-flow test coverage for: visible serial control, correct modal product context, and successful modal serial removal.
- [x] 3.2 Execute regression checks for serial add/remove interactions on `/pos/sell` and document verification results in the POS bug-fix notes.

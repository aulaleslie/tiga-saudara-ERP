## 1. Serial Modal UI Updates

- [x] 1.1 Add an explicit manual submit control (e.g., `Masukkan`) to the serial modal near the serial input field.
- [x] 1.2 Update serial modal close affordance label to close-oriented wording (e.g., `Tutup`) so it is not interpreted as submit.
- [x] 1.3 Add helper text in the modal clarifying that serial submission can be done via Enter or the manual submit control.

## 2. Serial Append Interaction Logic

- [x] 2.1 Refactor serial append trigger into a shared submit function used by both Enter key and manual submit button click.
- [x] 2.2 Ensure manual submit and Enter path both call existing `/serials/append` endpoint with active line context and identical success/error handling.
- [x] 2.3 Guard against duplicate in-flight submissions by disabling/rejecting repeated submit actions until current request resolves.

## 3. Close and State Semantics

- [x] 3.1 Ensure modal close actions never submit pending unsent serial input values.
- [x] 3.2 Preserve current burst behavior after successful append: clear input, refocus input, keep modal open.
- [x] 3.3 Keep modal status messaging consistent for successful append and validation/error responses across Enter and click paths.

## 4. Verification

- [x] 4.1 Manually verify typed serial + click submit appends serial and keeps modal open for next entry.
- [x] 4.2 Manually verify typed serial + close action does not append serial.
- [x] 4.3 Verify Enter/scanner flow remains unchanged and equivalent to manual submit in success/error outcomes.
- [x] 4.4 Add or update POS tests covering click-submit path, Enter parity, and close-with-unsent-input behavior.

## 1. Markup change

- [x] 1.1 In `Modules/Pos/Resources/views/sell/modals/cash_pickup.blade.php`, change `#pos-pickup-amount` from `type="number"` to `type="text" inputmode="numeric"`, and remove the now-unused `min="0"` and `step="0.01"` attributes.

## 2. Formatter (duplicated from staged-payment pattern)

- [x] 2.1 In the "Cash Pickup Modal" JS section of `Modules/Pos/Resources/views/sell.blade.php` (~line 3503+), add an input-event listener on `pickupAmountInput` that strips non-digit characters, stores the raw digit string in `pickupAmountInput.dataset.rawValue`, and sets `pickupAmountInput.value` to the `Intl.NumberFormat('id-ID')`-formatted display (empty string when no digits), mirroring `setupAmountInputFormatter()` in `public/js/pos-staged-payment.js`.
- [x] 2.2 Wire up the new listener during cash pickup modal initialization (same place the existing input listener is currently attached, ~line 3736).

## 3. Fix amount read sites

- [x] 3.1 Update the input listener's amount check (~line 3737) from `Number(pickupAmountInput.value || 0)` to `Number(pickupAmountInput.dataset.rawValue || 0)`.
- [x] 3.2 Update the "Lanjut" button click handler's amount check (~line 3789) from `Number(pickupAmountInput.value || 0)` to `Number(pickupAmountInput.dataset.rawValue || 0)`.
- [x] 3.3 Update the final confirm/submit handler's amount parse (~line 3835) from `Number(pickupAmountInput.value || 0)` to `Number(pickupAmountInput.dataset.rawValue || 0)`.

## 4. Fix reset sites

- [x] 4.1 Update the `pickupBtn` click-to-open handler (~line 3881) to clear `pickupAmountInput.dataset.rawValue = ''` alongside the existing `pickupAmountInput.value = ''`.
- [x] 4.2 Update the `hidden.bs.modal` reset handler (~line 3910) to clear `pickupAmountInput.dataset.rawValue = ''` alongside the existing `pickupAmountInput.value = ''`.

## 5. Focused verification (no full test suite)

- [x] 5.1 Manually click through the POS cash pickup flow in the browser: open "Pengambilan Kas", type a multi-digit amount, confirm thousand-separator formatting displays live, confirm "Lanjut" enable/disable still respects the live expected-cash max, complete supervisor+OTP, confirm the confirmation screen and final submitted amount match the entered digits (not a misparsed value).
- [x] 5.2 Manually verify modal reset: enter an amount, close without submitting, reopen the modal, confirm the field is empty and the previous amount does not leak into the next attempt.
- [x] 5.3 Manually verify the staged multi-payment "Jumlah Pembayaran (Rp)" field is visually and functionally unchanged (since its logic was duplicated, not modified).
- [x] 5.4 Run a focused PHP check only if any non-JS file was touched (expected: none) — e.g. `php artisan test --filter=Pickup` — otherwise skip backend tests entirely, since this change is JS/Blade-only and does not touch `PosSessionController` or `PosSupervisorApprovalService`.

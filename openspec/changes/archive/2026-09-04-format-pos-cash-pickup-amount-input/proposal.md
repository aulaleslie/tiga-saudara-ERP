## Why

The POS cash pickup "Jumlah Pengambilan" field is a raw `type="number"` input with no thousand-separator formatting, so cashiers entering large rupiah amounts (e.g. 1500000) get no visual grouping while typing. The POS staged multi-payment "Jumlah Pembayaran (Rp)" field already solves this with a live-formatted, digit-grouped display, and the user wants the cash pickup amount input to look and behave the same way.

## What Changes

- Change the cash pickup amount input (`#pos-pickup-amount` in `cash_pickup.blade.php`) from `type="number"` to `type="text" inputmode="numeric"`.
- Duplicate the staged-payment input-formatting behavior (strip non-digits on every keystroke, display with `Intl.NumberFormat('id-ID')` thousand separators, keep the raw digit string in `input.dataset.rawValue`) directly into the cash pickup JS in `sell.blade.php`, matching `pos-staged-payment.js`'s `setupAmountInputFormatter()` pattern. Logic is duplicated rather than extracted into a shared module, to avoid any risk of changing existing staged-payment behavior.
- Update the three call sites that currently parse the amount via `Number(pickupAmountInput.value || 0)` (input listener, "Lanjut" click handler, final confirm/submit handler) to instead read `Number(pickupAmountInput.dataset.rawValue || 0)`.
- Update the two modal-reset sites (`pickupBtn` click-to-open handler, `hidden.bs.modal` handler) to also clear `dataset.rawValue` alongside resetting `.value = ''`.
- No decimal support is introduced — cash pickup has never supported decimal amounts, and the formatting matches staged-payment's whole-rupiah-only display.
- Remove the now-inapplicable native `min`/`step` attributes from the input (validation already happens in JS against the live-fetched expected cash, and independently on the server).

## Capabilities

### New Capabilities
- `pos-cash-pickup-amount-input-formatting`: Governs the display formatting (thousand-separator, digit-only entry) of the cash pickup amount input, independent of the existing expected-cash-fetch and min/max validation behavior.

### Modified Capabilities
(none — existing `pos-cash-pickup-live-expected` validation/fetch requirements are unchanged; only the input's display format and the internal raw-value plumbing change)

## Impact

- `Modules/Pos/Resources/views/sell/modals/cash_pickup.blade.php` — input type/attribute change.
- `Modules/Pos/Resources/views/sell.blade.php` — cash pickup JS section (~lines 3503-3917): new formatter listener, updated parse sites (~3737, 3789, 3835), updated reset sites (~3881, 3910).
- No backend changes. `PosSessionController::pickup()` and `PosSupervisorApprovalService` are unaffected since they already validate the numeric amount independently server-side.
- No changes to `pos-staged-payment.js` or the staged-payment modal — formatting logic is duplicated, not shared, so that flow is fully untouched.

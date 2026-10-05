# Tasks

## 1. Release the selection-pending block

- [x] 1.1 In `Modules/Pos/Resources/views/sell.blade.php`, clear `searchResultSelectionFlowPending` in the Cari Produk button click handler before the modal is shown. Verify by updating the source test in 3.1.
- [x] 1.2 Clear `searchResultSelectionFlowPending` on no-response, success, error, and cancellation only for operations marked `fromSearchSelectionFlow`. Keep the guard in the card click handler and pass the marker through unit/bundle selection. Verify in 3.1.

## 2. Bind modal lifecycle listeners to CoreUI event names

- [x] 2.1 Bind Cari Produk `shown` (keyboard setup, keyword focus) and `hidden` (scanner refocus) listeners to both `.bs.modal` and `.coreui.modal` names, including the native fallback branch. Release on button click only; skip scanner refocus during an active selection. Verify in 3.1.
- [x] 2.2 Bind the unit-selection and bundle-selection modal `hidden` handlers to both `hidden.bs.modal` and `hidden.coreui.modal`. Verify with the source test in 3.1.
- [x] 2.3 Remove any temporary `console.log` probes (`[card]`, `[release]`) left in `sell.blade.php`. Verify with `grep -n "\[card\]\|\[release\]" Modules/Pos/Resources/views/sell.blade.php`, which should return nothing.

## 3. Focused verification

- [x] 3.1 Update `Modules/Pos/Tests/Feature/PosSearchResultSimpleProductStaysOpenTest.php`:
  - assert the CoreUI shown/hidden bindings and absence of the `show` release listener
  - assert guarded release for marked operations, button-click release, and scanner-refocus guard

  Run `php artisan test --filter=PosSearchResultSimpleProductStaysOpenTest` and confirm it passes.
- [x] 3.2 Run `php artisan test --filter='PosSearchResult|POSTransactionSaveAndPrint'` as a focused regression check on neighbouring POS view tests, and confirm it passes. Do not run the full suite.
- [x] 3.3 Hand off browser verification to a person, with this checklist:
  1. Select a serial-number bundle-parent product, choose Harga Normal, remove the line, then re-select it: the row is added.
  2. Do the same with an actual bundle.
  3. Do the same with a unit-conversion product.
  4. Dismiss the bundle dialog with ✕, then select again and scan: both work.
  5. Reopen Cari Produk: the keyword field is focused.
  6. Click a simple product, then a bundle product, then another card quickly — the bundle dialog works and the third click is ignored until search is reopened.

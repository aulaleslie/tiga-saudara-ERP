# Tasks

## 1. New-tab receipt without automatic printing

- [x] 1.1 Restore the synchronous `window.open('', '_blank')` Simpan dan Cetak flow, its failure cleanup and popup-blocked warning, then navigate to `receipt_url`. Verify the handler matches HEAD apart from its explanatory comment and contains no iframe path.
- [x] 1.2 Remove `->with('autoPrint', true)` from `printCurrentReceipt` while retaining its dedicated route, permissions, and active-cart/LOADED checks. Verify a feature test asserts the print-receipt response has no auto-print script.
- [x] 1.3 Revert the receipt view's `setTimeout` change to its HEAD `autoPrint` block without deleting that optional block.

## 2. Cari Produk focus and keyboard handling

- [x] 2.1 Bind Cari Produk `shown.bs.modal` handlers through jQuery when present and natively otherwise, with keyboard setup followed by keyword-input focus. Use one delegated keydown listener on the results container so modal reopen cannot add duplicate card handlers.

## 3. Focused verification

- [x] 3.1 Assert the save-and-print handler uses `window.open` and no iframe, the print-receipt response contains no auto-print script, and Cari Produk uses jQuery `shown.bs.modal` plus delegated keydown handling. Run `php artisan test --filter='POSTransactionSaveAndPrintTest|PosSearchResultSimpleProductStaysOpenTest'`. Do not run the full suite.
- [x] 3.2 Human browser check: Simpan dan Cetak opens a new receipt tab without a print dialog; close it with Ctrl+W, then confirm POS and Cari Produk keyword focus work. Enter on a preserved result card adds once. Clicking Cetak Struk prints a non-blank receipt. Record the result in the change.

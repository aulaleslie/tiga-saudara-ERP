# Tasks

## 1. Authorization and save flow

- [x] 1.1 Register `pos.transactions.print-current` in the centralized permission registry and relevant POS capability/bundle surfaces; verify the permission appears in the supported assignment UI and runtime registry parity checks.
- [x] 1.2 Add a guarded POST route and controller action requiring active POS session plus `pos.transactions.save`, `pos.transactions.load`, and `pos.transactions.print-current`; verify focused requests missing each permission return 403 without changing the cart.
- [x] 1.3 Add a cart-locked save-and-retain service operation that creates a draft for a new cart or updates the active loaded draft, keeps the same transaction loaded, and returns its code and fresh cart snapshot; verify focused tests cover first print, repeat print after edits, empty cart, and a failed operation.

## 2. POS screen and receipt

- [x] 2.1 Add **Simpan dan Cetak** beside **Simpan dan Buka Baru** with **Pilih Pembayaran** below as the primary action, and show it only when all three permissions are present; verify rendered Blade output for authorized and unauthorized users and responsive layout rules.
- [x] 2.2 Wire the button to save and retain once, refresh the current cart, then open the existing draft receipt and invoke printing; verify the handler disables repeat clicks, handles blocked popup or failed save with Bahasa Indonesia messages, and never opens a stale receipt after failure.
- [x] 2.3 Reuse the existing DRAFT/LOADED receipt view and transaction code without changing its presentation; verify a focused receipt test shows the saved line values and code and no payment or change details.

## 3. Focused verification

- [x] 3.1 Run focused POS feature tests for the new endpoint, permission combinations, first and repeated saves, and receipt data; record the exact commands and results. Do not plan a full test-suite run.
- [x] 3.2 Provide a short Bahasa Indonesia browser check list to the human developer covering first print, reprint after editing, blocked popup, failed save, and narrow-screen button layout; browser execution is performed by the human developer.

## Verification Notes

- `php artisan test Modules/Pos/Tests/Feature/POSTransactionSaveAndPrintTest.php` → 12 passed (incl. receipt access with only the three print permissions)
- `php artisan test --filter='POSTransactionSaveAndNewTest|PosSaveAndNewUiRegressionTest|POSTransactionLoadTest|POSTransactionUnloadTest|POSTransactionSaveAndPrintTest|POSTransactionEmptyBlockTest|POSReturnPermissionMatrixTest|PosCartWideOverrideRetirementTest'` → 53 passed (322 assertions)
- Receipt access fix: save-and-print returns `pos.sell.transactions.print-receipt` (guarded by the three print permissions, limited to the cart's active transaction); `--filter='POSTransactionSaveAndPrintTest|POSTransactionSaveAndNewTest|PosSaveAndNewUiRegressionTest|POSTransactionLoadTest'` → 31 passed (251 assertions)
- Review follow-up: print receipt requires LOADED status (409 otherwise) and auto-prints from the receipt page only via the print route; `POSTransactionSaveAndPrintTest` → 13 passed (80 assertions); `--filter='POSTransactionSaveAndPrintTest|POSTransactionSaveAndNewTest|PosSaveAndNewUiRegressionTest|POSTransactionLoadTest|Receipt'` → 220 passed

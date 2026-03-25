## 1. Setup & Preparation

- [x] 1.2 Open ENGLISH_STRINGS_INVENTORY.md and ENGLISH_STRINGS_CHECKLIST.csv for reference
- [x] 1.3 Review Phase 2 section of LOCALIZATION_IMPLEMENTATION_GUIDE.md

## 2. POS Controllers - Authorization Messages

- [x] 2.1 Replace messages in `/Modules/Pos/Http/Controllers/PosSellController.php`:
  - Line 38: "Active POS session context is required." → "Konteks sesi POS yang aktif diperlukan."
  - Line 47: "Authentication is required." → "Otentikasi diperlukan."
  - Line 725: "Unauthorized access to receipt." → "Akses ke struk tidak sah."

- [x] 2.2 Replace messages in `/Modules/Pos/Http/Controllers/PosSessionController.php`:
  - Line 87: "Authentication is required." → "Otentikasi diperlukan."
  - Line 266: "Authentication is required." → "Otentikasi diperlukan."
  - Line 276: "POS session not found for current setting." → "Sesi POS tidak ditemukan untuk pengaturan saat ini."

- [x] 2.3 Run POS controller tests: `php artisan test Modules/Pos/Tests/Feature/PosSell* Modules/Pos/Tests/Feature/PosSession*`
- [x] 2.4 Verify no test failures; update any assertions that compare error messages if needed

## 3. POS Validation Request

- [x] 3.1 Replace message in `/Modules/Pos/Http/Requests/StorePosSessionCloseRequest.php`:
  - Line 27: "Validation failed" → "Validasi gagal"

- [x] 3.2 Run validation tests: `php artisan test Modules/Pos/Tests/Feature/*SessionClose*`

## 4. Livewire Component Flash Messages - Barcode

- [x] 4.1 Replace messages in `/app/Livewire/Barcode/ProductTable.php`:
  - Line 43: "Max quantity is 100 per barcode generation!" → "Kuantitas maksimal adalah 100 per pembuatan barcode!"
  - Line 47: "Can not generate Barcode with this type of Product Code" → "Tidak dapat membuat Barcode dengan jenis Kode Produk ini"

- [x] 4.2 Test barcode component: Open barcode UI and verify messages appear in Indonesian

## 5. Livewire Component Flash Messages - Transfer & Adjustment

- [x] 5.1 Replace message in `/app/Livewire/Transfer/TransferProductTable.php`:
  - Line 61: "Already exists in the product list!" → "Sudah ada dalam daftar produk!"

- [x] 5.2 Replace message in `/app/Livewire/Adjustment/ProductTable.php`:
  - Line 55: "Already exists in the product list!" → "Sudah ada dalam daftar produk!"

- [x] 5.3 Test transfer component: Add duplicate product and verify flash message in Indonesian
- [x] 5.4 Test adjustment component: Add duplicate product and verify flash message in Indonesian

## 6. Cache Clearing & Testing

- [x] 6.1 Clear application caches: `php artisan cache:clear && php artisan view:clear`
- [x] 6.2 Run full test suite: `php artisan test`
- [x] 6.3 Fix any test failures related to string assertions

## 7. Manual Verification

- [x] 7.1 Test POS authorization: Try to access POS without session → verify Indonesian error message
- [x] 7.2 Test authentication: Try to access protected endpoints without auth → verify Indonesian error message
- [x] 7.3 Test receipt access: Try to view unauthorized receipt → verify Indonesian error message
- [x] 7.4 Test session validation: Submit invalid session close request → verify Indonesian validation message
- [x] 7.5 Test barcode generation: Exceed 100 quantity limit → verify Indonesian flash message
- [x] 7.6 Test transfer product: Add duplicate product → verify Indonesian flash message
- [x] 7.7 Test adjustment product: Add duplicate product → verify Indonesian flash message

## 8. Completion & Update Tracking

- [x] 8.1 Update ENGLISH_STRINGS_CHECKLIST.csv: Mark all Phase 2 rows as "COMPLETED"
- [x] 8.2 Run `git status` and `git diff` to review all changes
- [x] 8.3 Create commit: `git add . && git commit -m "feat(localization): implement phase 2 high-priority messages"`
- [x] 8.4 Create PR with summary of all Phase 2 changes completed

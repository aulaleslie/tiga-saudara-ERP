## 1. Backend Service Guard

- [x] 1.1 Update `PosTransactionService::loadToCart` status validation to strictly require `STATUS_DRAFT`.
- [x] 1.2 Implement atomic status check inside the `DB::transaction` block after `lockForUpdate()`.
- [x] 1.3 Ensure `PosTransactionConflictException` is thrown with the code `TRANSACTION_ALREADY_LOADED` if the status changed while waiting for the lock.

## 2. UI Action Visibility

- [x] 2.1 Update `buildActions` JavaScript function in `Modules/Pos/Resources/views/transactions/index.blade.php` to hide the "Muat" action for `LOADED` transactions.
- [x] 2.2 Verify that the "Loaded" transaction still shows the "Detail" and "Batalkan" actions where applicable.

## 3. Localization and Error Messages

- [x] 3.1 Update the translation or standard error message for `TRANSACTION_ALREADY_LOADED` to be user-friendly.
- [x] 3.2 Verify that the frontend correctly displays the error message when a load attempt fails due to concurrency.

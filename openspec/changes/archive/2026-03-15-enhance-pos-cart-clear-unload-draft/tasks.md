## 1. Backend Implementation

- [x] 1.1 Add `unload` method to `PosTransactionService` to revert `LOADED` status to `DRAFT`
- [x] 1.2 Modify `PosCartService::clear` to call `unload` for loaded transactions and bypass the empty-block check for authorized users

## 2. Verification

- [x] 2.1 Create `POSTransactionUnloadTest.php` to verify unloading behavior for Super Admins and authorized users
- [x] 2.2 Update `POSTransactionEmptyBlockTest.php` to reflect that clearing is no longer blocked for authorized users

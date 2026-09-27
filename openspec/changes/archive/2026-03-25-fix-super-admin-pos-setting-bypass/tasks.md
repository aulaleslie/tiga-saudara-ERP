## 1. Regression Testing

- [x] 1.1 Create `Modules/Pos/Tests/Feature/POSSuperAdminBypassTest.php` to capture the failing scenario (Super Admin blocked by setting check).
- [x] 1.2 Verify that the new test fails with "Actor user is not assigned to current setting".

## 2. Service Layer Implementation

- [x] 2.1 Update `PosSessionCloseService::closeSession` to allow Super Admin bypass.
- [x] 2.2 Update `PosSafeDropService::createSafeDrop` to allow Super Admin bypass.
- [x] 2.3 Update `PosSessionAdminCloseService::closeSessionAsAdmin` to allow Super Admin bypass.
- [x] 2.4 Update `PosSessionFinalizeService::finalizeSession` to allow Super Admin bypass.

## 3. Verification

- [x] 3.1 Run `php artisan test Modules/Pos/Tests/Feature/POSSuperAdminBypassTest.php` and ensure all scenarios pass.
- [x] 3.2 Run existing POS session tests to ensure no regressions.
    
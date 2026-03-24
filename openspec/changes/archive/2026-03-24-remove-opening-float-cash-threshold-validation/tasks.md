## 1. Remove Threshold Validation Code

- [x] 1.1 Edit `Modules/Pos/Services/PosSessionLifecycleService.php` and delete lines 117-131 (the entire threshold validation block inside the `if ($normalizedTerminalId !== null)` branch)

## 2. Update Tests

- [x] 2.1 Remove or mark as xfail `Modules/Pos/Tests/Feature/POSOpeningFloatCaptureTest.php::test_it_rejects_when_opening_float_total_is_less_than_or_equal_to_cash_threshold()` (line 136)
- [x] 2.2 Remove or mark as xfail `Modules/Pos/Tests/Feature/POSOpeningFloatCaptureTest.php::test_it_rejects_when_cash_threshold_is_null()` (line 157)
- [x] 2.3 Verify `Modules/Pos/Tests/Feature/POSSessionLifecycleTest.php::test_open_session_allows_float_below_cash_threshold()` still passes

## 3. Verification and Testing

- [x] 3.1 Run POS module unit tests: `php artisan test --filter="Pos.*Lifecycle"`
- [x] 3.2 Run POS module feature tests: `php artisan test --filter="POSOpeningFloatCaptureTest|POSSessionLifecycleTest"`
- [x] 3.3 Run full POS test suite to ensure no regressions
- [x] 3.4 Manually verify: Open a POS session with opening float below terminal cash threshold (if threshold is 50000, open with 25000) and confirm session opens successfully

## 4. Documentation

- [x] 4.1 Verify change is properly documented in this OpenSpec (proposal, design, specs created)

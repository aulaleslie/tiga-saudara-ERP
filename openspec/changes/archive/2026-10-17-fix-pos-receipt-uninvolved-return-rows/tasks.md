# Tasks

## 1. Receipt history correction

- [x] 1.1 Add a regression in `POSReceiptReturnProjectionTest.php` for a laptop replacement followed by a one-unit photo-paper cash return with a positive-quantity serialized `none` context row; verify the test exposes the unwanted second-entry laptop row before the fix.
- [x] 1.2 Skip explicit `RESOLUTION_NONE` rows during return-history item construction in the shared projection; verify the regression shows only photo paper in the second entry while preserving the first replacement, serial lineage, settlement fields, four remaining photo-paper units, and stored context evidence.

## 2. Focused verification

- [x] 2.1 Assert the rendered second return-history block omits the uninvolved laptop while the main purchase and first return retain it; verify with the focused regression filter.
- [x] 2.2 Run `php artisan test Modules/Pos/Tests/Feature/POSReceiptReturnProjectionTest.php` and record the result, covering existing cash-return, replacement, bundle, and endpoint-parity behavior. Use focused verification only; do not run the full suite or `composer test:fresh-sqlite`.

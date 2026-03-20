## 1. Fix Migration Ordering

- [x] 1.1 Rename `Modules/Pos/Database/Migrations/2026_03_17_000000_create_checkout_payment_tables.php` to `Modules/Pos/Database/Migrations/2026_08_13_000400_create_checkout_payment_tables.php`

## 2. Verification

- [x] 2.1 Run `php artisan migrate:fresh --seed` and confirm it completes without errors

## 1. Permission Registry

- [x] 1.1 Add `inventory.view_remaining_stock` to `app/Config/Permissions.php` (new grouping or existing shared/inventory group, with an Indonesian label consistent with existing entries)
- [x] 1.2 No manual seeding step needed locally beyond running `php artisan db:seed --class="Modules\User\Database\Seeders\PermissionsTableSeeder"` (or a fresh migrate+seed) to pick up the new entry for local testing — `deploy.bat` already runs this seeder unconditionally on every production deploy (step 7/10), so production sync is automatic once 1.1 lands

## 2. POS Product Search

- [x] 2.1 In `Modules/Pos/Services/PosProductSearchService.php`, gate `available_qty` inclusion in each mapped result on `auth()->user()->can('inventory.view_remaining_stock')` — omit the key entirely when the user lacks the permission, leaving all other fields (name, price, stock state) unchanged
- [x] 2.2 In `Modules/Pos/Resources/views/sell.blade.php`, update the card-building JS so the "Stok" line is only rendered when `available_qty` is present on the product object (no placeholder, no blank line)
- [x] 2.3 Confirm out-of-stock detection (`isOutOfStock`) and "Stok Kosong" badge logic still work correctly using whatever field they currently key off — adjust to use a stock-state field that remains present regardless of permission, if `isOutOfStock` currently derives from `available_qty` directly

## 3. Price Points ("Terminal Harga")

- [x] 3.1 In `app/Livewire/PricePoint/Browser.php`, gate `available_qty` / `formatted_available_qty` inclusion in product data on `auth()->user()->can('inventory.view_remaining_stock')` — omit both fields entirely when the user lacks the permission
- [x] 3.2 Confirm `stock_state` (in_stock/out_of_stock/service) computation remains unconditional and unaffected, since it drives badges independent of the numeric fields
- [x] 3.3 In `resources/views/livewire/price-point/browser.blade.php`, update the "Stok" block (both the out-of-stock and in-stock render branches) to render only when the quantity field is present in the product data

## 4. Verification

- [x] 4.1 Write/update focused unit or feature tests for `PosProductSearchService` covering: permitted user receives `available_qty`; unpermitted user's result omits `available_qty` but retains other fields and correct out-of-stock/service badge behavior
- [x] 4.2 Write/update focused feature tests for `Browser.php` (Price Points) covering the same permitted/unpermitted matrix, including that `formatted_available_qty` is also omitted for unpermitted users
- [x] 4.3 Run only the affected test files/filters (e.g. `php artisan test --filter=PosProductSearch`, `php artisan test --filter=PricePoint`) rather than the full suite, per instruction to keep verification focused
- [x] 4.4 Manually verify in a browser: log in as a user without `inventory.view_remaining_stock`, confirm the "Stok" element is fully absent (not blank) on both POS `Cari Produk` cards and Price Points cards, while out-of-stock/service badges still render correctly; then confirm a permitted user sees the "Stok" element exactly as before

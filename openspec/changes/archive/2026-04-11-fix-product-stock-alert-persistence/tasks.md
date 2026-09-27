## 1. Fix Core Persistence Logic

- [x] 1.1 Remove `product_stock_alert` from `$fieldsWithDefaults` array in `Modules/Product/Services/ProductCreator.php` to prevent it from being overwritten by a hardcoded zero.
- [x] 1.2 Implement an explicit integer fallback to `0` for `product_stock_alert` in `ProductCreator::create` before the `Product::create()` call to satisfy non-nullable database constraints.

## 2. Verification

- [x] 2.1 Manually create a product with a "Peringatan Jumlah Stok" value and verify it is correctly saved and displayed in the product details.
- [x] 2.2 Manually create a product leaving "Peringatan Jumlah Stok" empty and verify it defaults to 0 in the database.

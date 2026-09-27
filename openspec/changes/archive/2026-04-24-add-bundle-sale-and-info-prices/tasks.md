## 1. Database

- [x] 1.1 Create migration adding nullable `bundle_sale_price` to `product_bundles` without changing `price`
- [x] 1.2 Create migration adding nullable `informational_item_price` to `product_bundle_items` without changing `price`
- [x] 1.3 Backfill `bundle_sale_price` from parent product active-setting `product_prices.sale_price` where resolvable
- [x] 1.4 Backfill `informational_item_price` from item product active-setting `product_prices.sale_price` where resolvable

## 2. Product Bundle Persistence

- [x] 2.1 Update Product Bundle create validation to accept required/non-negative `bundle_sale_price`
- [x] 2.2 Update Product Bundle edit validation to accept required/non-negative `bundle_sale_price`
- [x] 2.3 Update bundle item validation to accept required/non-negative `informational_item_price` per item
- [x] 2.4 Persist `bundle_sale_price` on create/update without writing it to legacy `product_bundles.price`
- [x] 2.5 Persist each item's `informational_item_price` on create/update without writing it to legacy `product_bundle_items.price`

## 3. Product Bundle UI

- [x] 3.1 Hide the legacy `Harga Paket` field from the bundle create form
- [x] 3.2 Add editable `Harga Jual Paket` to the bundle create form, defaulting from parent product active-setting sale price
- [x] 3.3 Hide the legacy `Harga Paket` field from the bundle edit form
- [x] 3.4 Add editable `Harga Jual Paket` to the bundle edit form, showing saved value or a safe fallback
- [x] 3.5 Add editable `Harga Informasi Item` column to the `Item Paket` Livewire table
- [x] 3.6 Default `Harga Informasi Item` from selected item product active-setting sale price when product selection changes
- [x] 3.7 Ensure hidden form inputs submit product id, quantity, and informational item price for every item row

## 4. Product Detail List

- [x] 4.1 Remove legacy `product_bundles.price` display from the product detail bundle list
- [x] 4.2 Display `Harga Jual Paket` for each bundle in the product detail bundle list
- [x] 4.3 Display `Harga Informasi Item` for each bundled item row in the product detail bundle list

## 5. Compatibility Guardrails

- [x] 5.1 Verify Sales cart behavior remains unchanged by this Product Bundle CRUD change
- [x] 5.2 Verify POS bundle listing/cart behavior remains unchanged by this Product Bundle CRUD change
- [x] 5.3 Keep legacy `product_bundles.price` and `product_bundle_items.price` values untouched during create/edit operations

## 6. Tests

- [x] 6.1 Add migration/backfill coverage for new bundle and item price columns
- [x] 6.2 Add feature test for bundle create defaulting and persisting `Harga Jual Paket`
- [x] 6.3 Add feature or Livewire test for item selection defaulting and persisting `Harga Informasi Item`
- [x] 6.4 Add feature test that create/edit/product detail surfaces do not expose legacy `Harga Paket`
- [x] 6.5 Run targeted Product Bundle, Sales bundle, and POS bundle tests affected by pricing compatibility

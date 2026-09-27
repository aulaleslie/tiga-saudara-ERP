## 1. Database Migration

- [x] 1.1 Create migration to add nullable `setting_id` column (FK to `settings`) on `product_bundles`
- [x] 1.2 Add backfill logic: for each existing bundle, duplicate it (with all `product_bundle_items`) to every setting in `settings` table, then make `setting_id` NOT NULL

## 2. Model Layer

- [x] 2.1 Update `ProductBundle` model: add `setting_id` to fillable, add `setting()` BelongsTo relationship
- [x] 2.2 Update `Product::bundles()` relationship or add a scoped helper (the HasMany itself stays generic; scoping is done at query time)

## 3. ProductBundleResolver

- [x] 3.1 Add required `int $settingId` parameter to `forProduct()`, `forProducts()`, `isSellable()`, `areSellable()`
- [x] 3.2 Update queries to filter by `setting_id`
- [x] 3.3 Update cache key from `$productId` to `"{$productId}:{$settingId}"`

## 4. Product Detail Page (Controller + View)

- [x] 4.1 Update `ProductController::show` to pass `$settingId` into the bundles query: `$product->bundles()->where('setting_id', $settingId)->with('items.product')->get()`
- [x] 4.2 Display the active setting name next to the "Paket Penjualan" section header (so users know which branch's bundles they're viewing)

## 5. Bundle CRUD Controller

- [x] 5.1 Update `ProductBundleController::index` to filter bundles by `session('setting_id')`
- [x] 5.2 Update `ProductBundleController::create` — pass `settingId` to view for display context
- [x] 5.3 Update `ProductBundleController::store` — include `setting_id` from `session('setting_id')` in `ProductBundle::create()`
- [x] 5.4 Update `ProductBundleController::edit` — verify bundle's `setting_id` matches `session('setting_id')`, 404 otherwise
- [x] 5.5 Update `ProductBundleController::update` — verify bundle's `setting_id` matches active session
- [x] 5.6 Update `ProductBundleController::destroy` — verify bundle's `setting_id` matches active session

## 6. Verification

- [x] 6.1 Run migration on local database and verify bundles are duplicated correctly
- [x] 6.2 Verify product detail page shows only bundles for the active setting
- [x] 6.3 Verify creating a new bundle saves with the correct `setting_id`
- [x] 6.4 Verify editing/deleting a bundle from a different setting returns 404

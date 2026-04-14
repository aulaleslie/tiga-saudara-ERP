## 1. Controller Refactor

- [x] 1.1 Refactor `PosSellController@productBundles` to use `ProductBundleResolver` and apply `setting_id` from session.

## 2. Service Refactor

- [x] 2.1 Update `PosCartService@addLine` to add `setting_id` constraint when resolving bundles.
- [x] 2.2 Update `PosScanResolverService@isBundleParent` to accept and use `settingId`.
- [x] 2.3 Pass `settingId` to `isBundleParent` in `PosScanResolverService@resolve` and `formatProductExact`.
- [x] 2.4 Update `PosProductSearchService@search` to include `setting_id` filter in the `is_bundle_parent` existence check subquery.

## 3. Validation

- [x] 3.1 Verify that the bundle selection dialog only shows bundles for the current setting.
- [x] 3.2 Verify that search results correctly flag `is_bundle_parent` based on the active setting.
- [x] 3.3 Verify that adding a bundle to cart works correctly with the new scoping.

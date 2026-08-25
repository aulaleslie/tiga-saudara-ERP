## 1. Replica Group Persistence

- [x] 1.1 Add a Product module migration for nullable, indexed `product_bundles.replica_group_uuid`, with a compatible rollback and no historical-data backfill.
- [x] 1.2 Add the replica-group attribute/cast or model support needed to consistently persist and query bundle lineage.
- [x] 1.3 Update bundle creation to generate one UUID per creation operation and assign it to every setting copy inside the existing transaction.

## 2. Edit Surface and Validation

- [x] 2.1 Add boolean validation/normalization for the per-save `apply_price_to_all_businesses` update input without accepting a client-controlled group UUID.
- [x] 2.2 Render an unchecked `Terapkan harga ke semua bisnis` checkbox next to `Harga Jual Paket` for grouped bundles and preserve its submitted state after validation errors.
- [x] 2.3 For ungrouped historical bundles, omit or disable the actionable checkbox and render Indonesian guidance that the older bundle is not linked to other-business copies.

## 3. Transactional Price Synchronization

- [x] 3.1 Extend the authorized bundle update transaction so an opted-in save propagates only `bundle_sale_price` to existing rows with the route bundle's persisted non-null replica-group UUID.
- [x] 3.2 Preserve setting-local updates for name, description, active dates, enabled state, composition, and informational component prices whether or not price synchronization is selected.
- [x] 3.3 Explicitly guard the propagation path against null group identity and retain local-only behavior for unchecked or ineligible saves.
- [x] 3.4 Keep local deletion semantics and ensure missing/deleted group members or settings created later are neither recreated nor required by synchronization.

## 4. Focused Verification

- [x] 4.1 Add migration/model tests proving old records remain null, new creation operations assign one shared UUID per copy set, and separate creations receive different UUIDs.
- [x] 4.2 Add edit rendering and request tests for the Indonesian checkbox, unchecked default, validation redisplay, and historical-bundle guidance.
- [x] 4.3 Add update tests proving unchecked edits remain local and checked edits synchronize only `bundle_sale_price` while every other field remains local.
- [x] 4.4 Add safety tests proving null or different group identities are untouched, forged identity input cannot redirect propagation, nested-route/active-setting authorization remains enforced, and surviving partial groups can synchronize.
- [x] 4.5 Add a failure-path test proving any synchronized update failure rolls back both the active-setting update/component replacement and all propagated prices.
- [x] 4.6 Run the focused Product Bundle feature test suite (`ProductBundleSyncPriceAcrossBusinessesTest`, `ProductBundleReplicatedPricingTest`, `ProductBundleDefinitionIntegrityTest`, `ProductBundleSelfComponentAndOneLevelRegressionTest`). Note: `ProductBundleSyncPriceAcrossBusinessesTest` (5/5) and `ProductBundleReplicatedPricingTest` (6/6) passed completely; 3 pre-existing unrelated failures reproduced identically on the baseline branch (`ProductBundleDefinitionIntegrityTest::test_product_deletion_succeeds_after_bundle_references_are_removed`, `ProductBundleSelfComponentAndOneLevelRegressionTest::test_authoring_permits_self_component_and_bundle_capable_components`, and `ProductBundleSelfComponentAndOneLevelRegressionTest::test_normal_sales_dispatch_demand_for_self_component`).

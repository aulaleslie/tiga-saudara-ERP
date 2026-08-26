## 1. Bundle Event Identity Capture

- [x] 1.1 Update bundle creation feed capture to resolve the parent product and store `<product name> — <bundle name>` with the parent product code while preserving the existing transaction and operation grouping.
- [x] 1.2 Update single-business and replicated bundle price-update feed capture to use the same combined identity and current bundle name without changing no-op suppression or price snapshots.

## 2. Focused Verification

- [x] 2.1 Extend `ProductBundlePriceFeedIntegrationTest` to cover combined product/bundle identity and product code for bundle creation and bundle price updates, including the existing no-op behavior.
- [x] 2.2 Extend `ProductPriceFeedQueryServiceTest` to verify tokenized bundle-event search by parent product name, product code, and bundle name using stored event data.
- [x] 2.3 Extend `HomeProductPriceFeedPreviewTest` or the closest shared presentation test to verify the Home row renders the combined bundle identity and product code without requiring a current catalog lookup.
- [x] 2.4 Run only the focused bundle feed integration, query-service, and Home preview tests, and fix any regressions in the touched behavior.

## 3. OpenSpec Verification

- [x] 3.1 Validate `show-product-name-in-bundle-feed` with strict OpenSpec validation after implementation tasks and focused tests pass.

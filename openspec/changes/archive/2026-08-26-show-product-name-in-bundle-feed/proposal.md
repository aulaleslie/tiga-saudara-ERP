## Why

Bundle creation and bundle-price update entries in `Pembaruan Produk & Harga` currently identify only the bundle, which makes similarly named bundles difficult to associate with their parent catalog product. The feed should preserve and display both identities so users can recognize the affected product without opening or cross-referencing the bundle.

## What Changes

- Record future bundle-created and bundle-price-updated feed events with a stable display identity containing the parent product name and bundle name.
- Record the parent product code on those bundle events so the shared feed row, detail modal, and tokenized search provide the same product context.
- Present the combined identity consistently anywhere the shared product-price feed event is rendered, including Home and full history.
- Leave existing immutable historical feed events unchanged; they continue to render their stored bundle-only identity.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `product-price-update-feed`: Bundle event identity and search behavior will include the parent product name and code in addition to the bundle name.

## Impact

- Bundle event capture in `Modules/Product/Http/Controllers/ProductBundleController.php`.
- Stored `product_price_feed_events` display fields and the existing shared feed presentation/query behavior.
- Focused bundle feed integration and query tests; no new dependency, public API, or schema migration is expected.

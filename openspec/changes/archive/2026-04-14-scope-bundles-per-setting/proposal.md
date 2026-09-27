## Why

Product bundles (`product_bundles`) are currently global—a single bundle configuration is shared across all settings (branches). This means different stores cannot have different bundle configurations for the same product. Since pricing, stock, and other operational data are already scoped per setting, bundles should follow the same pattern so each branch can independently configure which items are bundled and at what price.

## What Changes

- **Add `setting_id` column** to `product_bundles` table (FK to `settings`)
- **Backfill existing bundles** by duplicating each existing bundle (with its items) to all available settings
- **Scope bundle CRUD** on the product detail page to the user's active `session('setting_id')`
- **Scope bundle queries** in `ProductBundleResolver` and `ProductController::show` to filter by `setting_id`
- **Pass `setting_id` implicitly** on bundle create/store from the active session — no UI dropdown needed

## Capabilities

### New Capabilities
- `bundle-setting-scope`: Scoping product bundle configuration (create, read, update, delete) per setting, so the same product can have different bundle configurations across different branches

### Modified Capabilities
_(none — no existing spec-level behavior changes, this is a new scoping dimension)_

## Impact

- **Database**: `product_bundles` schema gains `setting_id` FK column; existing data duplicated across all settings
- **Product Module**: `ProductBundleController` (index, create, store, edit, update, destroy), `ProductController::show`, bundle views
- **Support**: `ProductBundleResolver` (forProduct, forProducts, isSellable, areSellable)
- **POS Module** (future, not in this change): `PosProductSearchService`, `PosScanResolverService`, `PosCartService` bundle queries will need `setting_id` filtering
- **Sale Module** (future, not in this change): `ProductCart` Livewire component bundle selection queries

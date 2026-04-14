## Context

Product bundles (`product_bundles`) define bundled items for a parent product (e.g., "GRATIS CHARGER" for a laptop). Currently, bundles are global: keyed only by `parent_product_id` with no `setting_id` column. This means all branches share the same bundle configuration.

Other per-setting scoped data in the system includes: `product_prices`, `product_unit_conversion_prices`, `product_stocks`, serial numbers. The product entity itself is global (its `setting_id` column is deprecated). Bundles need to follow the same per-setting scoping pattern.

**Current schema:**
- `product_bundles`: `id`, `parent_product_id`, `name`, `description`, `price`, `active_from`, `active_to`
- No `setting_id` column exists

**Current consumers (product detail scope):**
- `ProductController::show` — loads bundles unfiltered
- `ProductBundleController` — all CRUD operations are setting-unaware
- `ProductBundleResolver` — queries by `parent_product_id` only

## Goals / Non-Goals

**Goals:**
- Add `setting_id` FK to `product_bundles` table
- Backfill existing bundles by duplicating them (with items) to all available settings
- Scope all bundle CRUD on the product detail page to the user's active `session('setting_id')`
- Scope `ProductBundleResolver` queries to accept and filter by `setting_id`

**Non-Goals:**
- POS module bundle scoping (future change — POS already has `settingId` in scope)
- Sale module (`ProductCart` Livewire) bundle scoping (future change)
- Deprecating `setting_id` on the `products` table (separate concern)
- Changing bundle item structure or pricing logic

## Decisions

### 1. Implicit setting_id from session (no UI selector)

**Decision:** Bundle create/store will use `session('setting_id')` automatically — no dropdown selector in the form.

**Rationale:** This is consistent with how prices, stocks, and other per-setting data work. The user's active setting context is already established at login/session level. Adding a dropdown would be inconsistent and confusing.

**Alternative considered:** Explicit setting dropdown on bundle create form — rejected because no other per-setting entity uses this pattern.

### 2. Duplicate existing bundles to all settings during migration

**Decision:** The migration will duplicate each existing bundle (and its `product_bundle_items`) to every available setting.

**Rationale:** With only 1 existing bundle and 6 settings, this is safe and ensures no branch loses existing bundle configuration. Assigning to just one setting could silently remove bundle availability from other branches.

**Alternative considered:** Assign existing bundles to the product's own `setting_id` — rejected because `product.setting_id` is deprecated and doesn't represent the correct intent.

### 3. ProductBundleResolver gains required settingId parameter

**Decision:** `forProduct()` and `forProducts()` will require a `$settingId` parameter. Callers must explicitly provide the setting context.

**Rationale:** Makes setting-scope explicit at the call site. Prevents accidental unscoped queries. Callers in POS/Sale modules will be updated in future changes.

**Alternative considered:** Optional parameter with fallback — rejected because it would silently return wrong data when callers forget to pass it.

### 4. Bundle uniqueness: no extra unique constraint

**Decision:** No unique constraint on `(parent_product_id, setting_id, name)`. The same name can appear for the same product+setting.

**Rationale:** Existing behavior allows duplicate names. Adding a constraint could break the duplication migration if names collide. Naming is a UI concern, not a data integrity concern.

## Risks / Trade-offs

- **Migration data volume:** Duplicating 1 bundle × 6 settings = 6 bundles + their items. Minimal risk.
- **Future POS/Sale consumers:** `ProductBundleResolver`, `PosProductSearchService`, `PosScanResolverService`, and `PosCartService` still query bundles without `setting_id`. These will return incorrect results until updated in a follow-up change. → **Mitigation:** Document as known limitation; POS/Sale scoping is an explicit non-goal.
- **Cache invalidation:** `ProductBundleResolver` uses a static in-memory cache keyed by `productId`. After this change, the cache key must include `settingId` to avoid cross-setting pollution. → **Mitigation:** Update cache key to `"{productId}:{settingId}"`.

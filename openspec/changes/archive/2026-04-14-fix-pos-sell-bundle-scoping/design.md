## Context

The system recently implemented per-setting scoping for product bundles, but several POS-specific services and controllers were missed during the initial rollout. These components still query the `product_bundles` table without a `setting_id` filter, causing configuration leakage between settings.

## Goals / Non-Goals

**Goals:**
- Enforce `setting_id` scoping in `PosSellController`, `PosCartService`, `PosProductSearchService`, and `PosScanResolverService`.
- Ensure the POS UI correctly reflects bundle availability for the active setting.

**Non-Goals:**
- Changing the underlying database schema (already done).
- Modifying non-POS bundle interactions (handled by previous changes).

## Decisions

### 1. Use `ProductBundleResolver` in Controller
**Decision**: Use `App\Support\ProductBundleResolver::forProduct()` in `PosSellController@productBundles`.
**Rationale**: This helper centralizes setting-scoped bundle hydration and caching. It's more robust than manual querying in the controller.

### 2. Explicit Subquery Scoping in Search Service
**Decision**: Add a `where pb.setting_id = ?` clause to the `is_bundle_parent` subquery in `PosProductSearchService`.
**Rationale**: Since this service uses fluent query builder for performance, we must manually inject the parameter.

### 3. Update Scan Resolver to accept Setting ID
**Decision**: Update `isBundleParent` method in `PosScanResolverService` to accept and use `$settingId`.
**Rationale**: The service already receives `$settingId` in its main `resolve` method, making it straightforward to pass it down.

## Risks / Trade-offs

- **[Risk]** Missing a query point leads to subtle bugs.
  - **Mitigation**: Grep for all references to `product_bundles` and `bundles()` relationship in the `Modules/Pos` directory.
- **[Trade-off]** Slightly more complex SQL in search.
  - **Mitigation**: The impact is negligible as it's an indexed column match.

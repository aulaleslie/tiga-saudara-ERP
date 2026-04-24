## Context

Product bundle configuration currently stores a legacy bundle-level `product_bundles.price` value shown as `Harga Paket`. The Sales and POS paths interpret that value as an add-on amount on top of the parent product price. The Product bundle item table also has an existing `product_bundle_items.price` column and some Livewire price handling, but the current Product Bundle CRUD UI does not expose or persist item price inputs.

The desired Product module behavior is different from the existing runtime pricing model: bundle configuration should expose a new final `Harga Jual Paket` value and a new per-item `Harga Informasi Item` value. The existing columns must remain untouched for compatibility, and Sales/POS behavior must not change in this proposal.

## Goals / Non-Goals

**Goals:**
- Add a new bundle-level persisted value for `Harga Jual Paket` that represents the final sale price for the parent product and selected bundle combined.
- Add a new item-level persisted value for `Harga Informasi Item` used as editable reference information per bundled item.
- Default `Harga Jual Paket` from the parent product's active-setting sale price during create.
- Default `Harga Informasi Item` from the selected item product's active-setting sale price when an item is selected.
- Hide the legacy `Harga Paket` field/value from Product Bundle create, edit, and product detail list UI.
- Preserve existing `product_bundles.price` and `product_bundle_items.price` data and behavior.

**Non-Goals:**
- Do not change Sales cart pricing behavior.
- Do not change POS bundle listing, cart, checkout, or receipt behavior.
- Do not migrate or reinterpret existing `product_bundles.price` as the new final bundle price.
- Do not remove existing legacy columns.
- Do not introduce customer tier-specific bundle sale pricing in this change.

## Decisions

### 1. Use new columns instead of repurposing legacy columns

Add new columns such as `product_bundles.bundle_sale_price` and `product_bundle_items.informational_item_price`. Keep `product_bundles.price` and `product_bundle_items.price` intact.

Rationale: Sales and POS still depend on legacy semantics. Reusing either existing price column would create hidden behavior changes and make rollback difficult.

Alternative considered: Rename or repurpose `product_bundles.price`. Rejected because existing runtime flows treat it as an add-on price.

### 2. Default from active-setting product prices

Resolve defaults from `product_prices` using the active `session('setting_id')`. `Harga Jual Paket` defaults from the parent product's sale price. `Harga Informasi Item` defaults from the selected item product's sale price.

Rationale: Product pricing is setting-scoped in this application, and bundle CRUD is already scoped to the active setting.

Alternative considered: Use legacy `products.sale_price` or `products.product_price`. Rejected because those values can be stale relative to `product_prices`.

### 3. Keep the new item price informational only

Persist and display `Harga Informasi Item`, but do not use it to alter Sales/POS totals in this change.

Rationale: The user explicitly wants Sales and POS handled later. Keeping this field informational avoids partial pricing migrations.

Alternative considered: Immediately use item prices to calculate bundle sale totals. Rejected because the intended final runtime source is the new bundle-level `Harga Jual Paket`, and runtime changes belong to a later Sales/POS proposal.

### 4. Hide legacy price UI without deleting legacy data

Remove the legacy `Harga Paket` input from create/edit pages and the legacy price display from the product detail bundle list. Existing database values remain available to Sales/POS until those flows are intentionally changed.

Rationale: This prevents users from continuing to configure the confusing add-on price while maintaining backward compatibility.

Alternative considered: Keep the legacy field with a deprecated label. Rejected because the user wants it hidden from Product Bundle create, edit, and list surfaces.

## Risks / Trade-offs

- Existing Sales/POS may continue using legacy hidden prices → Mitigation: document this as an explicit non-goal and schedule a separate Sales/POS pricing change.
- Existing bundles may have null new prices after migration → Mitigation: make new columns nullable initially and backfill where active setting product prices can be resolved.
- Defaulting item prices requires selected product price metadata in the Livewire row → Mitigation: update the product selection event payload or resolve the product price server-side when handling the selection.
- Users may expect `Harga Informasi Item` to affect totals → Mitigation: label it clearly as informational and avoid showing computed totals based on it in this change.
- Hidden legacy prices may surprise operators reviewing old bundles → Mitigation: product detail should display the new `Harga Jual Paket`; where new value is absent, show a clear empty/default state rather than legacy price.

## Migration Plan

1. Add nullable `bundle_sale_price` to `product_bundles`.
2. Add nullable `informational_item_price` to `product_bundle_items`.
3. Backfill `bundle_sale_price` from the parent product's `product_prices.sale_price` for the bundle's `setting_id` where available.
4. Backfill `informational_item_price` from each item's product `product_prices.sale_price` for the owning bundle's `setting_id` where available.
5. Leave legacy `product_bundles.price` and `product_bundle_items.price` unchanged.
6. Rollback drops only the new columns; legacy data remains untouched.

## Open Questions

- Should old bundles with missing active-setting product prices display `0`, blank, or a warning in the Product UI? The implementation should prefer a non-blocking fallback and validation on save.

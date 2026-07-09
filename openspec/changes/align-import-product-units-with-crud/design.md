## Context

The Product CRUD workflow now treats `products.base_unit_id` as the canonical "Unit Utama" for stock-managed products and validates it on update. The older `products.unit_id` and `products.product_unit` fields still exist and are read by legacy sale, purchase, report, and cart paths.

The Product create page and Product CSV upload already populate `base_unit_id`, but Sales import and Purchase import create missing products through their own `findOrCreateProduct()` paths and currently populate only `unit_id`. Because those imported products are stock-managed, Product edit locks the unit selector when stock exists. A locked selector with no `base_unit_id` cannot be corrected in the edit form, so unrelated changes such as price updates fail validation.

The shared unit dropdown has a `disabled` state for the selector itself, but its quick-create "+" button remains available when `allowCreate` is true. That breaks the expected UI contract for read-only or disabled fields.

## Goals / Non-Goals

**Goals:**
- Align Sales import and Purchase import product creation with Product CRUD unit persistence.
- Safely repair existing imported products that have `unit_id` but no `base_unit_id`.
- Preserve compatibility for legacy reads of `unit_id` and `product_unit`.
- Prevent quick-create actions for disabled/read-only field controls, beginning with Product edit unit controls.
- Add focused tests around the import paths, repair path, and disabled quick-add rendering.

**Non-Goals:**
- Replace all legacy `product_unit` usages across the application.
- Redesign product unit conversion behavior or conversion pricing.
- Change sale, purchase, stock, dispatch, or report lifecycle semantics.
- Allow editing a locked product unit from the Product edit page when stock exists.

## Decisions

### Decision: Populate both canonical and legacy unit fields on import-created products

When Sales import or Purchase import creates a missing product, it will set `base_unit_id` to the resolved imported `Unit` ID and keep `unit_id` set to the same ID. It should also populate `product_unit` from the resolved unit short name or name when that field is empty or available at creation time.

Rationale: `base_unit_id` satisfies the current Product CRUD contract, while `unit_id` and `product_unit` preserve existing legacy surfaces. Setting all available representations at creation avoids introducing a compatibility break while the codebase still has mixed unit reads.

Alternative considered: change Product edit to fall back from `base_unit_id` to `unit_id` at render/validation time. That would mask bad data but leave imported products internally inconsistent and would require repeated fallback logic in future code paths.

### Decision: Repair existing data once instead of relying only on runtime fallback

Add an idempotent data migration or narrowly scoped command invoked during deployment to set `base_unit_id = unit_id` for affected products where `stock_managed` is true, `base_unit_id` is null, and `unit_id` points to an existing unit. Where `product_unit` is blank, populate it from the unit short name/name.

Rationale: Existing affected records must become valid even before users edit them. The repair condition is narrow and uses already-related unit data.

Alternative considered: leave existing records for manual repair. That is not viable because the unit field can be locked and price-only edits can fail.

### Decision: Leave unresolved products visible instead of inventing a unit

If an affected existing product has neither `base_unit_id` nor a valid `unit_id`, the repair must not guess a unit. Those records should remain unchanged and, if implemented as a command, be logged or reported for manual review.

Rationale: assigning a default unit such as PCS could corrupt inventory semantics. A missing valid source unit is a data-quality issue, not a safe automatic inference.

### Decision: Make disabled quick-create controls unavailable

For dropdown components that accept a disabled/read-only state, quick-create and clear controls should be hidden or disabled whenever the owning selector is disabled. For Product edit unit controls, hide the unit quick-create button when the base unit field is locked.

Rationale: a disabled field communicates that the value cannot be changed. Opening a modal to create a value for that field is misleading and can result in changes unrelated to the locked product.

Alternative considered: keep the button active but reject the modal result. That creates avoidable interaction churn and does not match user expectations.

## Risks / Trade-offs

- Existing mixed unit semantics may hide additional paths that create products without `base_unit_id` -> Add tests for the known Sales and Purchase import paths and use a narrow query to identify affected records.
- Backfilling `product_unit` from unit metadata may choose `short_name` vs `name` inconsistently with older data -> Prefer `short_name` when present because imports resolve units by short name; fall back to `name`.
- A migration touching production product rows carries data risk -> Keep the update idempotent and narrowly constrained to rows with valid `unit_id` and missing `base_unit_id`.
- Hiding disabled quick-create buttons could affect other dropdown contexts if generalized too broadly -> Start with components that already expose a disabled state and verify existing active contexts still show quick-create actions.

## Migration Plan

1. Deploy code that aligns Sales import and Purchase import product creation.
2. Run the idempotent data repair in the same deployment via migration or documented command.
3. Verify no products remain in the affected valid-repair set: stock-managed, `base_unit_id` null, valid `unit_id`.
4. If rollback is required, code rollback is straightforward; repaired `base_unit_id` values should remain because they restore intended canonical product state and do not remove data.

## Open Questions

- Should the repair be implemented as a migration only, or as an Artisan command plus migration coverage? A migration is simpler for mandatory repair; a command gives better reporting for unresolved invalid-unit rows.

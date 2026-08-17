## Context

The UOM normalization feature (`received-purchase-uom-normalization`) lives entirely under the Purchase module today: routes at `/purchases/{purchase}/uom-normalize*`, `Modules/Purchase/Http/Controllers/UomNormalizationController.php`, and a view at `Modules/Purchase/Resources/views/uom-normalization/edit.blade.php`. Authorization is `PurchasePolicy::uomNormalize(User, Purchase)`, which requires the route-bound Purchase to be `RECEIVED`/`RECEIVED_PARTIALLY` and in the active setting.

Despite being purchase-routed, the feature's actual scope was already product-wide: `candidateLines()`, `preview()`, and `store()` all operate on the selected product's complete active-setting purchase/receipt history (via `UomNormalizationEligibilityService`/`UomNormalizationExecutionService`), not on the route Purchase's own lines. The route Purchase is only used for a one-time "is this product even on this purchase" boundary check in `searchProducts()`/`productBelongsToPurchase()`, and to gate authorization by that one purchase's status. Nothing in the service layer depends on a Purchase.

`Modules/Purchase/Http/Controllers/UomNormalizationController::history()` exists but is not wired to any view — it is unused UI-wise. There is no `ProductPolicy` class in the codebase yet.

## Goals / Non-Goals

**Goals:**
- Make the product the route-bound resource, eliminating the product-search step and the artificial "must reach it via one eligible Purchase" framing.
- Preserve every existing safety behavior: preview, product-wide/cross-setting eligibility checks, the two execution acknowledgments, and the full audit trail (`UomNormalizationBatch`/`UomNormalizationLine`).
- Reduce manual steps by defaulting candidate lines to fully selected once unit + factor are set, while keeping per-line checkboxes for transparency.

**Non-Goals:**
- No change to `UomNormalizationEligibilityService`, `UomNormalizationExecutionService`, or the entities/audit schema.
- No change to the `product:convert-uom` console command or its independent `ProductImportUomEligibilityService`/`ProductImportUomMutationService` — that remains a separate, unrelated correction path (whole-product, cross-setting rebase with document deletion), not something this change unifies with.
- No new bulk/no-selection execution mode; the operator still previews and explicitly acknowledges before executing.
- Not migrating or backfilling any historical batches — existing `UomNormalizationBatch` rows keep whatever Purchase-derived data they already recorded.

## Decisions

**1. Route/controller/view move to `Modules/Product/*`, keyed by `{product}` only.**
`edit()`, `searchUnits()`, `candidateLines()`, `preview()`, `store()` are ported into a new `Modules/Product/Http/Controllers/UomNormalizationController.php`, all keyed by the route-bound `Product`. `searchProducts()` and `productBelongsToPurchase()` are dropped entirely — there is no boundary check to make since the product is the route resource itself. `history()` is dropped (unused, and re-scoping it to "all batches for this product" is trivial to re-add later if a need appears — no reason to carry dead code forward).

**2. New `ProductPolicy::uomNormalize(User $user, Product $product): bool`.**
Replaces the Purchase-status gate with:
- `$product->stock_managed === true` and `$product->merged_into_id === null` (same eligibility signal already enforced deeper in `UomNormalizationEligibilityService::validateBatchSelection()`, surfaced earlier so the entry button/page doesn't appear for products it can never apply to).
- Active-setting scope: no longer "this Purchase belongs to my setting" but implicitly satisfied because `candidateLines()`/`preview()`/`store()` already scope all reads to `session('setting_id')`.
- Super Admin bypass retained, matching `PurchasePolicy::uomNormalize()`'s existing pattern.
- Permission check reuses the same permission key, `purchases.received.uom-normalize` — renaming it is unnecessary churn (it's an internal Spatie permission string, not user-facing) and would require a data migration for existing role grants. The Indonesian label already reads "Normalisasi UOM Penerimaan," which stays accurate.

This means a product with zero eligible purchase history can still open the page — eligibility failure is a normal "tidak ada baris pembelian yang dapat dinormalisasi" empty/blocked state on the page (rendered client-side once `candidateLines()` returns empty, or surfaced by `preview()`'s existing `errors` array), not a route-level 403. This is a deliberate behavior change from today (where an ineligible Purchase status blocks the route outright) and is the one place the "modified capability" delta spec needs a new scenario.

**3. Candidate lines default to fully selected.**
`toggleAllLines`-equivalent selection state defaults to "all" the moment `candidateLines()` resolves and factor > 0, instead of requiring a manual "select all" click or per-row checks. Implemented client-side only (Alpine.js state default), no backend contract change — `preview()`/`store()` still receive an explicit `purchase_detail_ids` array, so nothing bypasses today's requirement that the full active-scope selection be submitted.

**4. Old purchase-scoped routes/controller/view/button are deleted outright (not deprecated in place).**
Per explicit decision: this is a full replacement, not a parallel/legacy path. `Modules/Purchase/Routes/web.php`'s `purchases.uom-normalize.*` group, `Modules/Purchase/Http/Controllers/UomNormalizationController.php`, `Modules/Purchase/Resources/views/uom-normalization/edit.blade.php`, and the button block in `Modules/Purchase/Resources/views/show.blade.php` (lines ~548-552) are removed. `PurchasePolicy::uomNormalize()` is removed once nothing references it.

**5. Reuse existing services unchanged; only the controller layer changes.**
`UomNormalizationEligibilityService::generatePreview()`/`validateAll()` and `UomNormalizationExecutionService::execute()` already take `Product`, `Unit`, `factor`, `purchaseDetailIds`, `User`, `settingId`, `reason` — no `Purchase` parameter today. The new controller passes `session('setting_id')` directly (previously `$purchase->setting_id`, which was always equal to the active session setting anyway, per the old policy's own tenant check).

## Risks / Trade-offs

- **[Risk]** Dropping the route-level Purchase-status gate means the page is now reachable for products that have never had any eligible purchase/receipt activity, which could confuse operators who expect a hard block. → **Mitigation**: `candidateLines()` returning empty, or `preview()`'s existing `eligible: false` + `errors` array, already produce a clear in-page message; this is strictly more informative than a bare 403, and is the existing pattern the service layer already supports.
- **[Risk]** Defaulting all candidate lines to selected could make it easier to accidentally include a line the operator meant to exclude (e.g., a line they know is problematic). → **Mitigation**: Checkboxes remain individually togglable and visible before preview; nothing executes without an explicit preview + two acknowledgment checkboxes + confirm-modal, unchanged from today.
- **[Risk]** Existing tests (`UomNormalizationTest.php`, `UomNormalizationEndToEndTest.php`, `UomNormalizationMigrationTest.php`) assert against the old Purchase-scoped routes/policy. → **Mitigation**: Tracked as explicit tasks; these are route/setup changes, not new assertions on service behavior, since the service layer is untouched.
- **[Trade-off]** Removing `history()` (currently dead code) means there is no product-scoped audit-history endpoint after this change, even though `UomNormalizationBatch` records remain fully queryable in the database. Deferred rather than ported speculatively, consistent with "don't build for hypothetical future requirements."

## Migration Plan

1. Add new Product-scoped routes/controller/view (additive, no removal yet) and verify end-to-end manually against a real product.
2. Add `ProductPolicy::uomNormalize()` and the product-show-page entry button.
3. Remove the old Purchase-scoped routes, controller, view, and button in the same change (per the "replace, don't keep both" decision) — no separate deprecation window, since this is an internal ERP tool with a small, known operator base, not a public API with external consumers.
4. Update the three existing Purchase-scoped UOM normalization test files to target the new routes/policy.
5. No database migration needed — `UomNormalizationBatch`/`UomNormalizationLine` schema is unchanged.

Rollback: revert the commit/PR; no data migration to unwind since no schema changes are involved.

## Open Questions

- None outstanding — permission key reuse, `history()` removal, and full replacement (vs. dual entry points) were explicitly decided during exploration.

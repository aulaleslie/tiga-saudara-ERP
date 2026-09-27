## Context

Sales and purchase import services both contain ownership resolution in three places: grouping rows by invoice and tenant, resolving the document `setting_id`, and resolving stock/price ownership per imported product row. The current priority is Daizu detection, then CSV `Tag`, then product marker, with additional purchase-history fallback for non-marker stock ownership.

The desired behavior keeps Daizu as the highest-priority product-name rule, removes tag from ownership mapping, keeps tag syncing as metadata, and makes owner alignment deterministic for imported rows.

## Goals / Non-Goals

**Goals:**
- Make sales and purchase import ownership depend only on raw product name.
- Preserve whole-word Daizu detection for `KEDELE`, `KEDELAI`, and `RAGI`.
- Preserve CSV tag syncing as record metadata.
- Align document owner, stock owner, ProductPrice owner, and inventory Transaction owner for imported rows.
- Prevent tag differences from splitting invoice groups or duplicate checks.

**Non-Goals:**
- Do not change manual sales or purchase creation.
- Do not change historical imported records.
- Do not remove the tag relationship feature from sales or purchases.
- Do not change CSV staging, header normalization, or upload UI behavior except where ownership outcomes are displayed.
- Do not add database schema.

## Decisions

1. Centralize import ownership around a product-name rule.

   Both import services should use the same effective priority:
   - whole-word `KEDELE`, `KEDELAI`, or `RAGI` routes to Daizu Kedelai
   - otherwise product name starting with `*` routes to `CV TIGA NUSA COMPUTER`
   - otherwise product name ending with ` TP` routes to `CV TOP IT INTERNUSA`
   - otherwise route to `PERDANA`

   Alternative considered: keep tag as a fallback after markers. That keeps legacy behavior but leaves imports sensitive to source-system tags, which is the problem this change is addressing.

2. Keep tags as metadata after ownership is resolved.

   Existing `syncTags([trim($tag)])` behavior can remain after a sale or purchase is created. The tag must not be passed into or consulted by grouping, tenant resolution, stock owner resolution, ProductPrice owner selection, or duplicate tenant checks.

   Alternative considered: stop syncing tags entirely. That would remove ambiguity, but it would also lose potentially useful audit/filtering context from the original CSV.

3. Remove import stock-owner history fallback.

   The current stock resolution can fall back to the last non-Tiga-Nusa purchase owner for unmarked products. Under the new rule, non-Daizu unmarked imports route to `PERDANA`, and stock movement should follow that same owner. Keeping the fallback would allow document owner and inventory owner to diverge silently.

   Alternative considered: keep history fallback only for sales imports. That preserves legacy stock depletion behavior but breaks the new owner-alignment invariant and makes outcomes depend on prior imports.

4. Group invoice rows by invoice number plus product-name owner key.

   Grouping must use the same product-name owner key as tenant resolution. Rows from the same invoice can still split when product-name rules point to different owners, but they must not split solely because the `Tag` values differ.

   Alternative considered: group by invoice number only. That could mix rows that intentionally route to different settings in one document and would conflict with existing multi-tenant import behavior.

## Risks / Trade-offs

- Existing tests that assert tag-based routing will fail → update them to assert tag metadata preservation and product-name routing.
- Imports with previously meaningful non-Daizu tags will now route differently → document this as an intentional behavior change and verify with focused fixture rows.
- Removing history fallback may change stock locations for unmarked products → add owner-alignment tests that make this explicit.
- Sales and purchase services duplicate similar ownership code → keep changes parallel for this proposal; a shared resolver can be considered later if duplication becomes risky.

## Migration Plan

- Add or update tests for sales and purchase import ownership before changing service behavior.
- Update `SalesImportService` grouping, tenant resolution, stock resolution, and price-owner selection.
- Update `PurchaseImportService` grouping, tenant resolution, stock resolution, and price-owner selection.
- Keep existing tag syncing after document creation.
- Run focused import tests, then broader import-related tests if needed.

Rollback is code-only: restore the previous tag-aware resolution logic. No schema or data migration is required.

## Open Questions

None. Product-name ownership priority and tag metadata behavior have been decided.

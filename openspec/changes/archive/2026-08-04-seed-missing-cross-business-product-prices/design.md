## Context

The combined Accurate XLSX price-and-stock snapshot resolves one product and one owner setting from each source row. It applies the imported selling price to that owner only. `product_prices` is intentionally setting-scoped, but a product can therefore lack a price row for other currently available businesses, including businesses not represented by the `*`, `TP`, DAIZU, and Perdana marker rules.

## Goals / Non-Goals

**Goals:**

- Initialize a missing `product_prices` row for every available setting when a valid snapshot row supplies the first usable selling price for a product.
- Preserve every pre-existing non-owner setting price row.
- Keep owner price update, missing-row seeding, and the owner stock snapshot atomic.
- Work with dynamically loaded settings so future businesses need no code change.

**Non-Goals:**

- Copying stock, taxes, purchase prices, or owner markers to other settings.
- Updating an existing other-setting selling price from a source row owned by a different setting.
- Changing manual cross-business price management or generic product import behavior.

## Decisions

### Seed only absent price rows across all settings

After updating or creating the resolved owner's price row, query all settings and create a `ProductPrice` only where the product has no row for that setting. The seed uses the row's imported `SellPrice` for all three selling tiers and the established new-row defaults for purchase prices and tax IDs.

Alternative: upsert every setting to the imported price. Rejected because later owner-specific snapshot rows must not overwrite established prices belonging to other businesses.

### Resolve and mutate the owner before seeding

The resolved owner remains the sole direct target of the source row. The seed pass fills only structural gaps after that direct update. A later row for another owner then updates only that owner's now-existing row.

Alternative: defer all price updates until every workbook row is examined. Rejected because the existing per-target grouping and audit model already provides deterministic owner updates; missing-row seeding is additive and must not replace it.

### Keep all changes in the existing target transaction

Owner price update, cross-setting missing-row seeding, owner-location stock update, aggregate quantity update, transaction audit, and row results share one database transaction. Any failure rolls back all effects for that target while unrelated targets continue.

## Risks / Trade-offs

- [An import creates many price rows when many settings are missing] → Query existing rows for the product and insert only missing settings inside the existing transaction.
- [Concurrent imports both see a missing row] → Reuse the existing database uniqueness/creation conventions and treat a persistence collision as a target failure that rolls back cleanly.
- [A later owner row could accidentally overwrite a seeded value] → Limit direct update strictly to the row's resolved owner and never update a pre-existing other-setting row.

## Migration Plan

Deploy without a schema migration. New successful imports seed only missing rows; existing rows and historical imports remain unchanged. Roll back application code if necessary; already seeded rows remain valid product-price records and can be corrected through existing cross-business price management.

## Open Questions

None.

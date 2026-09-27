## Context

Purchase and sales imports currently create transaction detail rows using the final tax-included CSV unit price, but product catalog price synchronization is narrower than the rest of the product pricing architecture.

Normal product creation seeds `product_prices` for all settings through `ProductPrice::seedForSettings(...)`. POS and Sales pricing then read the active setting's `product_prices` row, including `sale_price`, `tier_1_price`, and `tier_2_price`. Import-created or import-updated products can leave other settings missing or at `0`, and sales import updates only one setting's `sale_price`.

Recent import ownership work also made purchase and sales imports resolve document, stock, transaction, and price ownership from product names. This change must preserve that ownership behavior while changing how imported prices are propagated across settings.

## Goals / Non-Goals

**Goals:**

- Keep purchase import as purchase-cost synchronization: update all settings with the imported final unit price as `last_purchase_price` and the computed weighted `average_purchase_price`.
- Keep sales import as selling-price synchronization: update all settings with the imported final unit price as `sale_price`, `tier_1_price`, and `tier_2_price`.
- Preserve sale and purchase document detail prices exactly as the current import calculations produce them.
- Avoid overwriting catalog selling prices from sales import rows whose final unit price is zero or blank.
- Preserve duplicate-skip behavior: skipped duplicate imports do not repair or backfill `product_prices`.
- Add focused regression coverage for both import services.

**Non-Goals:**

- No historical backfill command for rows already imported before this change.
- No schema migration or data rewrite.
- No change to import ownership resolution, Daizu detection, stock location selection, duplicate detection, or tag synchronization.
- No change to Product Import CSV behavior outside purchase and sales transaction imports.
- No change to POS or Sales cart pricing logic.

## Decisions

### Decision 1: Centralize all-setting ProductPrice synchronization per import service

Each import service should use a small helper around existing `ProductPrice::upsertFor(...)` or `ProductPrice::seedForSettings(...)` to write one price payload for every `Setting::id`.

Rationale: This follows the existing all-settings product creation model and avoids duplicating per-setting loops inline inside invoice processing.

Alternative considered: Only write the product-name-resolved owner setting. Rejected because POS and Sales pricing are active-setting scoped, so other settings can still see zero prices for the same global product.

### Decision 2: Purchase import writes purchase price fields only

Purchase import should propagate the final tax-included unit price into `last_purchase_price` and the computed weighted `average_purchase_price` across all settings. It should not update `sale_price`, `tier_1_price`, or `tier_2_price`.

Rationale: Purchase import data represents acquisition cost. Selling prices must come from sales imports or manual product pricing workflows.

Alternative considered: Seed selling prices from purchase import as an initial catalog baseline. Rejected because the clarified business rule separates purchase cost from selling price.

### Decision 3: Purchase average is copied consistently across settings

Purchase import should compute one weighted average using the current product-level quantity context already used by the service, then copy that resulting average to every setting's `product_prices` row.

Rationale: The current importer maintains one global product identity and global `product_quantity`, not independent per-setting product identities. Copying the same calculated value across settings keeps all active-setting views aligned.

Alternative considered: Recalculate average independently per setting based on each setting's existing average. Rejected because this would make catalog purchase-cost display diverge by setting even though imports identify one global product.

### Decision 4: Sales import writes base and tier selling prices across all settings

Sales import should overwrite `sale_price`, `tier_1_price`, and `tier_2_price` with the same positive final tax-included unit price for every setting. The latest processed row wins when the same product appears more than once.

Rationale: POS and Sales tier pricing require all three selling fields to be populated. The clarified rule intentionally keeps all customer tiers equal for imported sales prices.

Alternative considered: Only update `sale_price` and let tier pricing fall back to it. Rejected because existing screens and reports expose tier fields directly, and explicit tier values avoid zero-price confusion.

### Decision 5: Zero or blank sales import prices do not update catalog prices

Sales import should continue to create sale details with the imported calculated price, including zero, but it should skip `product_prices` synchronization when the final unit price is not greater than zero.

Rationale: A zero sale detail may reflect source data, but using it to overwrite catalog prices would break future POS and Sales pricing.

Alternative considered: Mark zero-price sales rows invalid. Rejected because the clarified behavior keeps row import semantics unchanged and only protects catalog price synchronization.

## Risks / Trade-offs

- [Risk] A sales CSV processed out of chronological order can overwrite catalog prices with older prices. → Mitigation: This is intentional under "latest processed row wins"; users should process files in the desired order.
- [Risk] Updating all settings can overwrite intentionally different per-setting selling prices. → Mitigation: The clarified import rule makes sales imports authoritative across all settings and tiers.
- [Risk] Existing already-imported products with zero prices remain unchanged. → Mitigation: Historical repair is explicitly out of scope; a separate backfill change can be proposed if needed.
- [Risk] Import performance may add work proportional to number of settings per row. → Mitigation: Settings are small reference data; if needed, cache setting IDs per batch/service invocation.

## Migration Plan

Deploy as a code-only change with focused tests. No migration or manual data operation is required.

Rollback is a code rollback. Product price rows updated by imports during the deployed window are not automatically reverted.

## Open Questions

None. The clarified decisions are captured in the proposal and reflected in the specs.

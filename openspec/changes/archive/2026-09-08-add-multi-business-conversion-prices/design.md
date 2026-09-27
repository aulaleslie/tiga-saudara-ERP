## Context

The existing Blade page uses CrossBusinessPriceController, CrossBusinessPriceUpdateRequest, and CrossBusinessPriceService to load and atomically save ProductPrice rows with version checks. ProductUnitConversion stores shared unit/factor definitions, and ProductUnitConversionPrice stores price and enablement flags per setting.

Read-only Tinker investigation of product 2300 in tiga_saudara found one shared conversion (ID 23, KOTAK = 12 PCS) and seven business prices of Rp22.500,00. Service/model values matched raw database rows. These are observations, not hardcoded defaults.

ProductController already updates shared conversion definitions, writes existing conversion prices only for the active setting, seeds new conversions across settings, and deletes shared conversions. Preserve these behaviors.

## Goals / Non-Goals

**Goals:**
- Compare and edit base and conversion prices across businesses in one page.
- Preserve independent business prices and globally shared unit/factor definitions.
- Retain permission checks, decimal precision, explicit copying, cancellation, and atomic conflict handling.

**Non-Goals:**
- Editing conversion units/factors on the multi-business price page.
- Conversion tiers, separate conversion purchase prices, or enablement controls.
- Automatic price recalculation after factor changes.
- Schema redesign, full-suite testing, or automated browser testing.

## Decisions

### Reuse existing storage and routes

Extend the current controller/request/service and Blade view. Eager-load conversions, units, and prices and index cells by conversion ID and setting ID. No migration is expected. Duplicating conversion definitions per business was rejected because a shared record already guarantees shared factors.

### Separate base and conversion matrices

Title the existing section Harga Satuan Dasar — {unit}. Add Harga Satuan Konversi with one business per row and one conversion per column, headed by unit name and factor relative to the base unit. Use stable conversion IDs for input and copy-action identity, never labels or factors. A responsive table accommodates additional conversions; show an empty-state message when none exist. Separate per-unit cards were considered but repeat business names and impede side-by-side comparison.

Retain Ubah/Batal/Simpan for the whole page. Conversion fields use the existing two-decimal Rupiah conventions. Average purchase price remains read-only. Copying updates only the chosen conversion across businesses and only changes form state.

### Missing rows remain distinct from zero

Render missing conversion prices as Belum diatur with blank input. Blank for an originally missing cell leaves it absent on save; entering zero creates an explicit zero price. Existing prices require a non-negative value with at most two decimal places and cannot be cleared. Cancel restores missing-state markers as well as numeric values. New price rows use the existing enabled defaults; existing sales_enabled and purchase_enabled flags are preserved by price-only updates.

### Atomic validation and stale-state protection

Submit base prices, a complete conversion/business cell matrix, and a signed snapshot describing business IDs, conversion IDs, unit/base-unit IDs, factors, row presence, versions, and original price values. Verify snapshot integrity and product binding. Compare current database state under transaction locks before writing. Detect same-second edits by comparing values as well as timestamps.

Reject missing/duplicate/foreign business or conversion identities, invalid values, new/deleted conversion definitions, changed units/factors, and concurrent price creation/update/deletion. Roll back both sections on any failure. Conversion-free products continue to save base prices normally.

Coordinate the product-edit conversion mutation path and cross-business save on the same product-row lock acquired before reading/mutating conversions. This serializes structure changes, including added rows that existing-row locks cannot cover. Review lock order in touched paths and use deterministic ordering. Retain current base-price feed recording; do not misrepresent conversion changes as base-price changes.

### Preserve the shared lifecycle

Changing an existing unit/factor on product edit changes the single shared record, so every business sees the new definition. Only the active business's submitted price changes. Other business prices remain numerically unchanged even when the factor changes. New conversions seed the initial price to all businesses; deletion removes the shared conversion for all. Add explanatory product-edit copy about global structure and local prices where needed.

## Risks / Trade-offs

- Wide conversion matrix → Responsive horizontal scrolling with explicit unit/factor headers.
- Factor changes alter the meaning of unchanged prices → Explain shared-factor/local-price behavior in product edit and reject stale price-page submissions.
- Concurrent structure changes evade row-only price locks → Coordinate on a product-row lock and validate the structure snapshot.
- Missing versus zero can be obscured by masking → Preserve absence explicitly through display, cancellation, validation errors, and saving.
- Historical base-price formatting specs mention whole Rupiah despite current decimal implementation → Preserve current two-decimal behavior; avoid unrelated specification cleanup.

## Migration Plan

Deploy application changes without a data migration or backfill. Verify focused affected tests in an isolated test database. A human checks browser behavior using disposable test data; do not mutate product 2300 as an automated verification fixture. Rollback restores prior application code; existing conversion prices remain compatible.

## Open Questions

None requiring a product decision. Implementation should confirm the existing conversion deletion cleanup and lock order, preserving established lifecycle behavior.

## Context

The Print Barcode workspace uses a dedicated Livewire search component. Its current `globalSearch` query finds product name, SKU, category, and brand, then displays results for manual selection. The input's Enter action only refreshes that result list, so a keyboard-wedge scanner cannot complete selection on its own.

The workspace already owns duplicate-row merging and label-quantity increments in `BarcodeBatchWorkspace::addProduct`. Primary product barcodes are stored in `products.barcode`; unit-conversion barcode lookup is intentionally deferred.

## Goals / Non-Goals

**Goals:**

- Discover products from their stored primary barcode alongside the existing free-text search fields.
- Let a trimmed exact primary-barcode value submitted with Enter add the matched product through the existing workspace selection event.
- Preserve existing suggestion-list behavior for typed text and barcode values that do not resolve exactly.

**Non-Goals:**

- Resolving `ProductUnitConversion` or barcode-registry conversion entries.
- Printing a barcode other than the selected product's primary stored barcode.
- Changing barcode assignment, barcode normalization, print validation, pricing, or batch submission.

## Decisions

### Match the primary barcode in the existing product search scope

The existing `Product::globalSearch` scope will include `products.barcode` as another matchable field. This keeps product discovery in the same query path and preserves the existing result shape and selection event.

Alternative: create a barcode-only search path. This would duplicate result construction and make normal typing and barcode lookup behave differently without a need.

### Use an exact primary-barcode lookup only for Enter submission

When Enter is pressed, the component will trim the input and look for one product whose primary barcode exactly equals the submitted value. If found, it will dispatch the same `productSelected` event used by clicking a suggestion, then reset its input. This routes scanner selection through existing row-merging logic.

If there is no exact primary-barcode match, Enter will retain the current behavior of refreshing/displaying search results. This avoids treating partial text or a broad match as an unintended product selection.

Alternative: automatically add the first fuzzy search result. This is unsafe for manually typed terms and scanners with an invalid or incomplete value.

### Defer conversion barcode lookup

Only `products.barcode` participates in both discovery and exact scanner selection. Conversion barcode support needs an explicit future decision about whether it should print the parent product's primary barcode or the scanned conversion barcode.

## Risks / Trade-offs

- [A scanner sends a barcode with unexpected whitespace] → Trim the submitted value before exact lookup, while leaving the stored barcode unchanged.
- [An unmatched scan leaves the operator unsure why nothing was added] → Preserve the existing visible search results/no-results feedback rather than silently clearing the input.
- [Barcode data is historically duplicated] → Use the first resolved primary-barcode product consistently; existing barcode-identity constraints are expected to prevent new duplicate primary values.

## Migration Plan

Deploy the Livewire query and Enter-handling change with focused tests. No migration, data backfill, endpoint change, or client configuration is required. Rollback restores name/SKU/category/brand-only lookup and manual result selection; no persisted state is affected.

## Open Questions

None.

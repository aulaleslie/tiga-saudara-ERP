## Context

Standard Sales create/edit uses `ProductCart` to build cart rows. Product and bundle additions already use UUID cart row IDs, so selecting the same product more than once can produce separate cart rows in the UI.

The document save path currently changes that meaning. `SaleService::createSale()` and `SaleService::updateSale()` call `SaleCartAggregator::aggregate($cartItems)` before `SaleNormalizer::normalize()`, which collapses rows by product, tax, and bundle. The saved `sale_details` records therefore represent aggregated demand buckets rather than the user's document rows.

Dispatch has a separate fulfillment model. `dispatch_details` does not reference `sale_detail_id`; dispatch screens and validation aggregate from all sale details by product/tax/bundle against the sale parent. That aggregation belongs in dispatch and should remain available there.

## Goals / Non-Goals

**Goals:**
- Preserve every standard Sales cart row as a distinct `sale_details` row during create and update.
- Ensure selecting a product from standard Sales product search always creates a new cart row and never appends to an existing row.
- Preserve per-parent bundle item rows so each saved bundle parent `sale_details` row owns its own `sale_bundle_items`.
- Keep sale header totals financially equivalent to the visible cart totals.
- Keep dispatch aggregation behavior based on the sale parent and product/tax/bundle quantities.
- Add regression coverage for create, update, edit hydration, bundle rows, and dispatch aggregation.

**Non-Goals:**
- POS cart, POS checkout, POS receipts, and POS transaction snapshots.
- Sales import behavior.
- Adding per-line note/description fields.
- Database schema changes unless implementation discovers a required missing column.
- Changing invoice or delivery-slip layout beyond naturally showing preserved sale detail rows where those views already iterate sale details.

## Decisions

1. Preserve rows by bypassing save-time aggregation for standard Sales.

   `SaleService` should pass cart items directly to `SaleNormalizer::normalize()` for standard Sales create/update instead of passing `SaleCartAggregator::aggregate($cartItems)`. `SaleNormalizer` already accepts iterable inputs and calculates header totals by summing normalized details, so this keeps the existing normalization and financial calculation surface.

   Alternative considered: include row identity in `SaleCartAggregator`'s grouping key. That would preserve rows indirectly, but it keeps document save coupled to a fulfillment aggregator and makes the line-preservation rule harder to reason about.

2. Keep `SaleCartAggregator` available for demand grouping.

   The aggregator may still be useful for explicit reporting or dispatch-oriented logic. This change should not delete it or repurpose it as the document persistence path.

   Alternative considered: remove the aggregator from the codebase. That is unnecessary and increases blast radius.

3. Keep dispatch aggregation unchanged in behavior.

   Dispatch should continue to aggregate `sale_details` and `sale_bundle_items` by product/tax/bundle to determine dispatchable quantity. Preserved document rows should only change how many `sale_details` rows exist, not how much can be dispatched.

   Alternative considered: linking dispatch details to individual sale detail rows. That conflicts with the current parent-sale fulfillment model and would unnecessarily complicate partial shipment, serial assignment, and stock location selection.

4. Treat product selection as append-only row creation in standard Sales.

   Product search selection should always call the add-row path and create a new cart row. Any existing duplicate-row behavior should be protected with tests so future changes do not reintroduce append/merge behavior.

   Alternative considered: append only when product/tax/bundle/price/discount all match. That returns to bucket semantics and prevents users from intentionally creating repeated document rows.

## Risks / Trade-offs

- Duplicate sale details may affect code that assumed one row per product/tax/bundle -> Add targeted tests for dispatch aggregation, sale status calculation, and invoice/detail views.
- Bundle child rows could attach to the wrong parent if preservation is implemented carelessly -> Persist bundle items inside the existing per-detail creation loop and test duplicate bundle parents.
- Header totals could drift if direct cart-item normalization differs from aggregated normalization -> Test duplicate lines with tax, discount, shipping, and bundle totals.
- Legacy controller store/update paths still delegate to `SaleService` -> Cover the shared service behavior rather than only Livewire UI behavior.
- Existing historical sales remain aggregated -> No migration is needed; the new behavior applies to future create/update operations.

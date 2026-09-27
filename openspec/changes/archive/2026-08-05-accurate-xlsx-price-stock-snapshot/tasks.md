## 1. Consolidate snapshot entry points and workbook staging

- [x] 1.1 Update the Product import routes, controller validation, upload guidance, and navigation so the Accurate XLSX price-and-stock snapshot is the supported entry point for new stock snapshots and the CSV stock snapshot entry point/template is removed or disabled.
- [x] 1.2 Extend the Accurate XLSX staging reader to require and persist `Stock`, while retaining `Name*`, `SellPrice`, optional `ProductCode`, and `*Unit` source evidence with normalized header handling and actionable errors.
- [x] 1.3 Update batch labels and detail-page copy/table columns to describe combined price-and-stock snapshots without breaking historical batch rendering.

## 2. Resolve targets through shared owner and product rules

- [x] 2.1 Refactor the combined snapshot processor to use the shared DAIZU-priority owner resolver for owner setting, clean product name, and product normalization.
- [x] 2.2 Resolve the owner’s target location and PKP status before mutation, producing row-level errors for missing settings or locations.
- [x] 2.3 Reuse deterministic existing-product matching for stock: unique code/name agreement, then exact clean name, then canonical normalized name; skip unmatched products and error ambiguous candidates without creating any product or unit.
- [x] 2.4 Validate signed Accurate stock values and preflight duplicate `(product_id, setting_id)` groups so conflicting selling prices or stock snapshots cannot depend on workbook order.

## 3. Apply atomic price and stock snapshots

- [x] 3.1 Implement per-target transactional synchronization of the resolved owner’s three selling tiers and the owner-location `ProductStock` snapshot.
- [x] 3.2 Preserve stock-snapshot overwrite semantics for positive, zero, and negative stock values; update aggregate product quantity by the location-row delta.
- [x] 3.3 Populate tax/non-tax stock buckets from the resolved owner’s PKP status and create the `ADJ` transaction from the latest owner/location ledger quantity.
- [x] 3.4 Persist combined row metadata and references for product resolution, owner/location, price before/after, stock before/after/delta, bucket deltas, and stock transaction.
- [x] 3.5 Ensure persistence errors roll back both price and stock effects and retain incompatible undo as unavailable for combined snapshot batches.

## 4. Verify behavior

- [x] 4.1 Add workbook-reader and request/UI tests for required `Stock`, valid Accurate XLSX uploads, generic MIME acceptance, and retired CSV stock entry behavior.
- [x] 4.2 Add processor tests for marker and DAIZU owner precedence, owner-location routing, PKP/non-PKP bucket assignments, and positive/zero/negative stock replacement.
- [x] 4.3 Add matching tests for blank product codes, normalized product-name matches, code/name disagreement, ambiguous names, and unmatched products that do not create products, stock, price, units, or transactions.
- [x] 4.4 Add atomicity and duplicate-group tests proving price/stock rollback together, equivalent duplicates mutate once, and conflicting price or stock values mutate neither effect.
- [x] 4.5 Add batch-detail tests for combined audit visibility and run focused Product import tests, followed by the project’s appropriate Laravel test command.

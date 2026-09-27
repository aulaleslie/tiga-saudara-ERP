## Context

`ProcessSalesPriceSnapshotBatch` already reads Accurate XLSX exports and applies owner-specific selling tiers after resolving products with `SalesImportMarkerResolver`. It stages only `Name*`, `ProductCode`, and `SellPrice`, leaving the workbook's `Stock` and `*Unit` fields unused. The older CSV stock snapshot flow has the required overwrite, owner-location, PKP bucket, and `ADJ`-transaction behavior, but uses a separate marker resolver, cannot route DAIZU, and creates products when matching fails.

The supplied Accurate export has `Name*`, `ProductCode`, `Stock`, `*Unit`, and `SellPrice`; most product codes are blank. The combined flow must therefore treat shared normalized product-name matching as authoritative, with an optional code used only as a deterministic consistency check.

## Goals / Non-Goals

**Goals:**

- Make the existing sales-price snapshot XLSX workflow the stock snapshot entry point for all owners, including DAIZU.
- Apply the workbook `Stock` value as an exact owner-location replacement and retain the existing tax/non-tax bucket and ledger-adjustment semantics.
- Resolve the product once through the shared DAIZU-aware marker/name normalization and use that same resolved product for both price and stock.
- Avoid creating products, units, locations, or price/stock mutations for unmatched or ambiguous input.
- Provide row audit metadata that explains both price and stock outcomes.

**Non-Goals:**

- Importing a separate CSV stock snapshot, modifying the generic product CSV importer, or creating missing products from a snapshot.
- Updating purchase prices, tax assignments on products, product units, categories, bundles, or serial records.
- Reconciling stock across multiple locations for an owner; the established first configured owner location remains the target.
- Changing the existing definition of a DAIZU product or the existing normalized-name aliases.

## Decisions

### Reuse the Accurate XLSX price snapshot batch and job

Extend the existing `sales_price_snapshot` batch type and `ProcessSalesPriceSnapshotBatch` rather than create a second XLSX stock type. One source row supplies one owner/product target and should produce a cohesive audited result.

Alternative: dispatch a stock job after the price job. Rejected because the two jobs could resolve or mutate different targets and cannot make price and stock atomic together.

### Require and stage `Stock` with the existing price columns

The XLSX reader will normalize and require `Name*`, `SellPrice`, and `Stock`; `ProductCode` remains optional and `*Unit` is staged for source evidence but does not create or change units. The upload page and batch label continue to use the sales-price snapshot path, now described as a price-and-stock snapshot.

Alternative: permit missing `Stock` and run price-only rows. Rejected because this feature replaces the CSV stock snapshot path; accepting a partial workbook would make a missing stock column dangerously easy to overlook.

### Resolve owner and product once through shared rules

Use `SalesImportMarkerResolver` for all combined rows. DAIZU keyword detection has priority over `*`, trailing `TP`, and the Perdana default. Resolve the matching setting and its first location. Product matching stays deterministic: a unique nonblank code candidate must agree with the normalized-name candidate; otherwise use unique clean-name then canonical-name matching. No match is skipped and ambiguity is an error.

Alternative: reuse the CSV stock resolver unchanged. Rejected because it lacks DAIZU priority and its simpler exact-name strategy could select or create a different product from the price workflow.

### Stock is an exact owner-location snapshot

For each resolved row, set `product_stocks.quantity` to the signed `Stock` value. Put the complete snapshot in `quantity_tax` for PKP settings or `quantity_non_tax` for non-PKP settings, setting the other bucket to zero. Update the aggregate `products.product_quantity` by the change in the owner-location stock row. Create an `ADJ` transaction whose previous quantity comes from the latest owner/location ledger quantity and whose delta is `after - previous`.

Alternative: interpret `Stock` as an increment. Rejected because Accurate's product export reports current on-hand stock and the legacy stock import already establishes overwrite semantics.

### Commit price and stock effects together per resolved target

Validate duplicate groups before mutation. Each valid `(product, owner)` target performs price tier synchronization and stock snapshot mutation inside one database transaction; an exception rolls back both. Equivalent duplicate source rows cause no additional mutation; conflicting price or stock snapshots reject the whole target group.

Alternative: retain the current price-only grouping transaction and write stock afterward. Rejected because a partial price-only or stock-only row would leave the external snapshot only half applied.

### Retire the standalone CSV stock upload as the normal stock-snapshot path

The Product import surface will direct snapshot users to the Accurate XLSX price-and-stock upload and remove or disable the separate CSV stock snapshot entry point/template. Historical CSV batches remain visible and their existing audit rows remain readable.

Alternative: keep both entry points indefinitely. Rejected because their owner/product rules would diverge and operators could still select the weaker, product-creating path.

## Risks / Trade-offs

- [A valid product export has an accidental or stale stock column] → Require `Stock`, show its before/after/delta in the batch, retain the ADJ audit record, and preserve asynchronous row visibility.
- [DAIZU setting or location is absent] → Fail only the affected row before any mutation and identify the unresolved owner/location.
- [A mostly blank `ProductCode` workbook has name collisions] → Use existing exact/canonical matching and report ambiguous candidates instead of guessing or creating products.
- [Duplicate workbook rows disagree on price or stock] → Detect conflicts per `(product_id, setting_id)` before writing either effect.
- [Existing stock-oriented undo cannot reverse price changes safely] → Keep undo unavailable for combined snapshot batches; recover through a corrected re-import or a database backup/operational correction.

## Migration Plan

1. Deploy the combined reader, resolver usage, atomic mutator, UI copy, and tests together.
2. Retain historical `sales_price_snapshot` and CSV `stock_snapshot` batches unchanged for monitoring.
3. Direct new stock snapshot uploads to the Accurate XLSX workflow; remove or disable the CSV snapshot upload controls.
4. Roll back application code if necessary. No database migration or historical data rewrite is required; any already-applied snapshot remains auditable through its `ADJ` transactions and must be corrected by a subsequent approved import or restore procedure.

## Open Questions

None. The requested policy is to require existing normalized product-name resolution, skip unmatched products, and replace stock values using the owner setting's tax bucket.

## Context

`product:export-tiga-nusa-prices` already produces an XLSX workbook with CV TIGA NUSA COMPUTER and CV TOP IT INTERNUSA worksheets. Every worksheet contains all catalog products at row 5 onward, with headers on row 4. The existing web import is deliberately incompatible: it expects Accurate headers, resolves owner from product-name markers, and atomically changes both stock and all three selling tiers to one value.

This change needs a controlled round-trip path for the exported workbook. Product prices are company-scoped in `product_prices`; product names are the only identity column in the existing export. The module already has upload authorization, durable `product_import_batches` and `product_import_rows`, XLSX reading, background jobs, and a batch-detail screen that can be extended without schema changes.

## Goals / Non-Goals

**Goals:**

- Let an authorized product editor upload the two-company export workbook and update each company's `sale_price`, `tier_1_price`, and `tier_2_price` independently.
- Treat worksheet identity—not product markers or the active session setting—as the company scope.
- Give users row-level outcomes and previous/resulting tier evidence through the existing import-batch monitor.
- Ensure invalid or ambiguous source data cannot create a partial price update for that target.

**Non-Goals:**

- Create products, price rows, settings, locations, stock records, or inventory transactions.
- Update purchase-price columns, tax fields, legacy product price fields, bundle prices, or conversion prices.
- Replace or alter the Accurate price-and-stock snapshot workflow.
- Support arbitrary company worksheets, CSV input, or renaming/reordering the two required worksheets.

## Decisions

### Use a dedicated import type and processing job

Add a dedicated price-workbook upload page, route, batch type, and queued job. The upload controller will check the existing `products.edit` ability, validate a readable `.xlsx`, store it, and create a batch without requiring a meaningful location because no stock operation occurs. The job will read both required worksheets and stage every source row with its sheet/company context in `raw_json`.

Alternative considered: extend `ProcessSalesPriceSnapshotBatch`. Rejected because its required Accurate columns, owner-marker routing, stock prerequisites, seeding behavior, and tier-copy behavior contradict this import's contract.

### Validate the complete workbook before applying rows

The job will require exactly one worksheet named `CV TIGA NUSA COMPUTER` and exactly one named `CV TOP IT INTERNUSA`; it will read row 4 as the header row and require `Nama Produk`, `Harga Jual`, `Harga Tier 1`, and `Harga Tier 2`. The two purchase-cost columns are recognized but ignored. Unexpected extra worksheets will be rejected to prevent a user from assuming they were processed. The workbook is failed before any price mutation when its required sheets or headers are invalid.

Alternative considered: use the active sheet or infer sheets by position. Rejected because the intended company scope would then be implicit and unsafe.

### Resolve products by exact, normalized product name and keep company scope sheet-bound

For a staged row, trim and collapse whitespace in `Nama Produk`, then match the equivalent normalized catalog product name. Exactly one match is required. The selected worksheet's exact-name setting is resolved once and becomes the only `(product_id, setting_id)` target; owner markers are never parsed.

Alternative considered: add an optional product-code column. Rejected for this change because the current export does not contain it; a later export-format revision can add a stronger identifier compatibly.

### Apply only supplied tier values, with zero as valid

Each selling column is parsed with the established decimal parser. A blank cell means no change for that individual tier; a supplied number from 0 through 99,999,999.99 updates that tier. Rows where all three selling-tier cells are blank are skipped. Existing `product_prices` rows are updated in one database transaction; no missing price row is created, so a workbook cannot silently introduce new cross-company pricing.

Alternative considered: treat blanks as zero or copy `Harga Jual` into tiers. Rejected because both would destroy intentionally different tier prices during an ordinary partial edit.

### Detect duplicate targets before mutations and retain audit metadata

Rows that resolve to the same `(product_id, setting_id)` must carry the same supplied values for every tier they both supply; otherwise the target is an error and none of its rows change prices. Equivalent duplicates are applied once and later rows are marked duplicate. Successful metadata includes worksheet/company, product match, supplied tier values, previous tiers, resulting tiers, and whether a price actually changed. The batch monitor gets a dedicated presentation branch and has no undo action because no safe generic reversal exists.

Alternative considered: let last worksheet row win. Rejected because row ordering is an accidental conflict-resolution policy.

## Risks / Trade-offs

- [Product names are not unique or are renamed after export] → Reject ambiguous/unmatched rows with a row-level reason; users correct the workbook or catalog before re-uploading.
- [The database has a product without a price row for a target company] → Skip/error that row without creating a row; this retains company price isolation and surfaces the data gap.
- [An operator edits title/header rows or tabs] → Validate exact required sheet names and header row before staging any price row.
- [Large catalog workbook] → Stream/stage in bounded batches and group targets before mutation, reusing existing import-job patterns.
- [No generic undo] → Retain row-level before/after audit evidence; correction is a subsequent import with deliberate values.

## Migration Plan

No database migration is required. Deploy the upload/page/job and the additive `ProductImportBatch` type. Rollback removes the entry point and job code; prior price values are not automatically reversible, so any imported values require a corrective import before rollback if necessary.

## Open Questions

None. Blank selling-tier cells preserve existing values; numeric zero is an explicit price update.

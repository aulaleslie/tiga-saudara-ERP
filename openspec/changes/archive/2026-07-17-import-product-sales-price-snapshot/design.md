## Context

The Product module already has queued CSV import batches, row staging, import monitoring, and `product_prices` records keyed by `(product_id, setting_id)`. Existing sales and stock imports also establish shared product-name marker rules through `App\Support\SalesImportMarkerResolver`: Daizu products take priority, a leading `*` identifies CV TIGA NUSA COMPUTER, a trailing ` TP` identifies CV TOP IT INTERNUSA, and an unmarked name identifies PERDANA.

The supplied Accurate workbook has 4,859 data rows and uses `Name*`, `ProductCode`, `*Unit`, and `SellPrice` columns. Every product code is blank, so product identity must be resolved from normalized names. A read-only comparison against the current database found 1,234 resolvable product-owner rows covering 1,029 of 1,056 ERP products. Ninety-seven matched products have different prices between owners, making owner-specific writes essential. The file also contains thousands of catalog products that do not exist in the ERP, zero prices, and two rows that collide with duplicate ERP identities.

## Goals / Non-Goals

**Goals:**

- Accept the Accurate XLSX product export through an explicit sales-price snapshot workflow.
- Reuse the same clean-name, marker, Daizu, alias, and canonical normalization rules as other imports.
- Update `sale_price`, `tier_1_price`, and `tier_2_price` to the same positive `SellPrice` in the resolved owner's `product_prices` row.
- Avoid product creation and protect all unrelated product, stock, purchase-price, and tax data.
- Validate and stage rows before price mutation, isolate row failures, and expose actionable batch and row audit information.
- Keep processing suitable for workbooks of at least the observed 4,859-row size.

**Non-Goals:**

- Creating missing products, product codes, units, categories, brands, stock, or stock transactions.
- Updating legacy price columns on `products`.
- Updating `last_purchase_price`, `average_purchase_price`, tax IDs, bundle prices, or unit-conversion prices.
- Treating `Stock`, `BuyPrice`, account, tax, minimum-stock, or category columns as mutation inputs.
- Globally copying one source row's price to every setting.
- Providing price rollback through the existing stock-oriented batch undo action.

## Decisions

### 1. Add a dedicated import type and entry point

Introduce `sales_price_snapshot` as a `ProductImportBatch` type with its own upload route, form, template/help text, batch label, and detail table. The endpoint requires product edit authorization and accepts `.xlsx` only for the initial capability.

This is preferred over expanding generic product upload auto-detection because a price-only operation has materially different permissions and mutation safety. It also prevents an Accurate export from being mistaken for a product-creation import.

The existing `source_csv_path` column can store the workbook path for backward compatibility despite its legacy name. Renaming it would create unnecessary migration and compatibility work; code should treat it as a source-file path.

### 2. Parse XLSX with the installed spreadsheet library

Use PhpSpreadsheet's read-only reader, select the active worksheet, normalize header case/BOM/whitespace, and require `Name*` and `SellPrice`. `ProductCode` is optional because it is blank in the supplied workbook. Other columns are retained in raw row payload only when useful for audit and are ignored for mutation.

Values are read as displayed or calculated scalar values and passed through a locale-tolerant decimal parser that accepts Accurate values such as `400,000.00`. Blank, non-numeric, zero, and negative selling prices are not applied.

This uses an existing dependency and avoids creating a transient CSV conversion artifact.

### 3. Stage and validate before applying price changes

Use a dedicated queued processor rather than adding another large branch to generic product creation logic. Its phases are:

1. Open the workbook and validate headers.
2. Stage every source row in `product_import_rows` with its row number and raw values.
3. Resolve owner, clean name, product match, target setting, and numeric price into row result metadata.
4. Detect ambiguous matches and duplicate/conflicting `(product_id, setting_id)` targets before applying any member of a conflicting group.
5. Apply valid target rows in bounded chunks, with one database transaction per row.
6. Finalize batch counters and status even when some rows are skipped or fail.

Prevalidation prevents source order from silently selecting a winner when two rows target the same product-owner price with different values. Per-row transactions allow unrelated valid rows to succeed.

### 4. Reuse shared owner and product normalization rules

Owner resolution uses `SalesImportMarkerResolver` and follows this priority:

1. A clean or raw name meeting the existing Daizu criteria resolves to DAIZU KEDELAI.
2. A leading `*` resolves to CV TIGA NUSA COMPUTER.
3. A trailing ` TP` resolves to CV TOP IT INTERNUSA.
4. An unmarked row resolves to PERDANA.

The marker is removed before matching. Matching then proceeds through deterministic confidence levels:

1. If a nonblank product code is supplied, find a unique case-insensitive exact code match.
2. Find a unique case-insensitive exact match on the whitespace-normalized clean product name.
3. Find a unique match using `SalesImportMarkerResolver::normalizeProductName`, including its punctuation handling and aliases.

If code and name resolve to different products, or any applicable level produces multiple candidates, the row is ambiguous and is not applied. A broader fuzzy/substring match is deliberately excluded because price writes require deterministic identity.

### 5. Write all three selling tiers only for the resolved owner

For each valid positive row, lock or atomically upsert the `product_prices` record identified by the matched product and resolved setting, then set:

```text
sale_price   = imported SellPrice
tier_1_price = imported SellPrice
tier_2_price = imported SellPrice
```

The write preserves `last_purchase_price`, `average_purchase_price`, `purchase_tax_id`, and `sale_tax_id`. It does not update other settings. If the target `product_prices` row is absent, create it while preserving the same three-field-only intent and leaving unrelated nullable fields unchanged/defaulted.

Updating all tiers is intentional for initial price setup and matches the user's confirmed business rule. Owner-specific writes are required because the workbook contains different prices for the same normalized product under different markers.

### 6. Classify non-applied rows without creating products

- Blank, zero, or negative `SellPrice`: `skipped` with a price-specific reason.
- No existing product match: `skipped` and reported; never create a product.
- Missing owner setting: `error` because configuration prevents a valid target.
- Ambiguous or conflicting identity/target: `error` with candidate/conflict context.
- Workbook/header/read failure: fail the batch before price mutation.
- Database failure during a valid row: `error`; continue with unrelated rows.

Batch counters continue using the established processed/success/error model, with skipped rows visible by row status and queryable for detail summaries. This avoids a schema migration solely for a skipped counter.

### 7. Preserve a row-level audit trail and disable misleading undo

Successful row metadata records raw and clean names, marker, match strategy, product ID/name, owner setting ID/name, imported value, previous three selling prices, resulting three selling prices, and whether the write changed data. Non-applied metadata records enough normalized identity and candidate information to diagnose the outcome.

The batch does not receive `undo_available_until`, so the existing stock-only undo button is not shown. A future audited price rollback could be added separately; silently presenting the current undo action would be unsafe.

## Risks / Trade-offs

- **[Canonical normalization can collapse genuinely distinct punctuated names]** → Require a unique normalized candidate and reject collisions rather than guessing.
- **[The database already contains duplicate or near-duplicate product names]** → Report candidate product IDs/names and require manual catalog cleanup for ambiguous rows.
- **[The export contains many products not present in the ERP]** → Skip them without creation so a price maintenance operation cannot expand the catalog.
- **[Owner company names may be missing or renamed]** → Use the established owner-resolution behavior and fail affected rows with an explicit owner configuration message.
- **[Large XLSX parsing consumes more memory than streaming CSV]** → Configure a read-only worksheet reader, avoid loading styles/formulas when unnecessary, stage in chunks, and test with a workbook at least as large as the supplied file.
- **[Partial success requires operational review]** → Make counts and row filters prominent and retain raw/result metadata for every row.
- **[No automatic undo for applied prices]** → Store previous values for audit, disable the incompatible undo control, and require a corrected snapshot import or manual edit for remediation.

## Migration Plan

1. Deploy the new batch type, XLSX upload UI/controller, processor, batch presentation, and tests without changing existing import routes or types.
2. Ensure queue workers use the deployed code before enabling the new entry point.
3. Run a staging import of the supplied workbook and review matched, skipped, ambiguous, and error counts against the discovery baseline.
4. Apply the production workbook and review the completed batch before treating prices as initialized.

Rollback consists of disabling/removing the new entry point and processor while leaving existing import behavior intact. Applied price values are data changes and are not automatically reversed; row metadata preserves the previous values needed for a deliberate recovery operation.

## Open Questions

None. The owner-specific scope, shared normalization behavior, positive-price guard, no-product-creation rule, and synchronization of all three selling tiers are defined.

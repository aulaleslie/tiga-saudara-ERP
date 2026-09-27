## Context

Purchase CSV exports such as `Purchase-2026-S2.csv` contain `Kode Produk`, but the current upload mapper does not retain that field and `PurchaseImportService::findOrCreateProduct` always generates an SKU for a missing product. Product names are already marker-normalized (`*` prefix and ` TP` suffix removed) before a case-insensitive name cache/lookup.

The import must preserve the product selected by its normalized name. In particular, an existing product with the same normalized name must not have its code changed merely because a later row presents a different code. The reference CSV also contains two distinct normalized names that both use `DL ES621`, while the product-code database column is globally unique.

## Goals / Non-Goals

**Goals:**

- Retain the optional source product-code value from the purchase CSV through staging and processing.
- Assign a safe imported code only when creating a product for a new marker-normalized name.
- Make the earliest existing product deterministic when legacy same-name records exist.
- Preserve import continuity by falling back to the established generated-SKU behavior for blank or conflicting codes.

**Non-Goals:**

- Do not use product code as a product lookup or merge key for purchase imports.
- Do not update a code on an existing product, deduplicate historical products, or change product-code uniqueness.
- Do not change sales import behavior, manual product creation, ownership routing, units, prices, or stock effects.

## Decisions

### Name-first product resolution

The importer will parse the existing purchase marker syntax first, then resolve a product using the cleaned name case-insensitively. The database query will explicitly order by ascending product ID, matching the requirement to respect the first product created when old data contains normalized-name duplicates. The normalized-name cache remains authoritative for subsequent rows in the running batch.

Using product code as a first or secondary lookup was rejected: the `DL ES621` source example proves that a supplier/export code can represent more than one distinct product name. Merging by that code would assign purchase details to the wrong catalogue product.

### Optional code propagation and sanitization

The upload header mapper will recognize `Kode Produk` and compatible English labels, stage the optional value in the row JSON, and pass it to product creation. Product codes are trimmed before use; blank results are treated as absent.

### Safe imported-code assignment

After confirming that no normalized-name product exists, product creation will use the imported code only if no existing product owns that code. Otherwise it will use the importer’s normal generated SKU. This same check covers a duplicate code encountered earlier in the same batch because newly created products are cached/visible to later rows.

Rejecting code-conflict rows was rejected because the requested behavior is to continue creating the later distinct product with an auto-generated code. Updating the first product or creating a duplicate code is prohibited by the name-first rule and the unique database index.

## Risks / Trade-offs

- [Concurrent import workers can both observe a free code before one inserts it] → Preserve the database unique constraint; handle a duplicate-key result by retrying creation with a generated SKU, within the existing transaction/error-handling pattern.
- [Legacy products can share a normalized name] → Query in ascending ID order and cache that selected first product for the batch.
- [Database collation can treat code case differently] → Check code availability using the same normalized/trimmed comparison semantics as the product-code unique index and rely on the index as final protection.
- [Source exports reuse codes across names] → Do not merge by code; generate a code for the later new name and retain its source name.

## Migration Plan

No database migration is required. Deploy the mapper and service changes with focused tests. Existing products and historical import rows remain unchanged; new imports gain the optional code behavior. Rollback consists of reverting the application changes, with already-created products retaining whichever code was assigned during import.

## Open Questions

None. The resolved policy is to generate a fallback code for a new normalized name whose imported code is blank or already assigned to another product.

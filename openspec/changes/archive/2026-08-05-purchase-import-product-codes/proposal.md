## Why

Historical purchase exports include a `Kode Produk` column, but the purchase importer currently discards it and assigns a generated SKU to every newly created product. Importing the source code when it is safe preserves the source catalogue while keeping product-name identity authoritative when codes are missing or reused.

## What Changes

- Accept and stage the optional `Kode Produk` CSV column (including supported English aliases).
- Create a newly discovered purchase-import product with its trimmed imported product code when that code is available and unused.
- Resolve products by marker-normalized product name before considering a code, preserving the first existing product and its code without updates.
- Generate the normal fallback SKU when the imported code is blank or is already assigned to a different product name.
- Add coverage for marker-normalized name reuse and product-code collisions in the same batch and against existing catalogue data.

## Capabilities

### New Capabilities

- `purchase-import-product-code-assignment`: Assign imported product codes to new purchase-import products while keeping normalized product-name matching authoritative.

### Modified Capabilities

- None.

## Impact

- Affects `Modules/Purchase/Http/Controllers/PurchaseUploadController.php`, `Modules/Purchase/Jobs/StagePurchaseImportRows.php`, and `Modules/Purchase/Services/PurchaseImportService.php`.
- Extends staged purchase import row JSON and product creation behavior; no schema or public API change is expected.
- Adds focused purchase import tests and uses `upload-data/purchase/Purchase-2026-S2.csv` as a documented real-world collision reference.

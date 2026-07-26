## 1. Export command

- [x] 1.1 Create `Modules/Product/Console/ExportBarcodesCommand.php` with signature `product:export-barcodes {--path=}`, following the structure of the existing `PreflightBarcodesCommand`
- [x] 1.2 Query barcoded products with `WHERE barcode IS NOT NULL AND barcode != ''`, selecting `product_name` and `barcode` only, with no `setting_id` filter
- [x] 1.3 Write the CSV with header `product_name,barcode`, emitting `product_name` verbatim with no normalization, marker stripping, alias mapping, case folding, or whitespace collapsing
- [x] 1.4 Default the output path when `--path` is omitted, and chunk the query so large catalogs stream rather than loading fully into memory
- [x] 1.5 Print the written row count on completion, and report zero (rather than failing) when no product holds a barcode
- [x] 1.6 Register `ExportBarcodesCommand` in `ProductServiceProvider::$this->commands([...])`

## 2. Restore command skeleton

- [x] 2.1 Create `Modules/Product/Console/ImportBarcodesCommand.php` with signature `product:import-barcodes {path} {--dry-run}`
- [x] 2.2 Validate the file exists and is readable, and fail early with a clear message if not
- [x] 2.3 Parse the CSV, tolerating the header row, and skip structurally blank rows
- [x] 2.4 Register `ImportBarcodesCommand` in `ProductServiceProvider::$this->commands([...])`

## 3. Matching and skip classification

- [x] 3.1 For each row, look up products by exact `product_name` with no `setting_id` scoping
- [x] 3.2 Classify zero matches as `not_found` and record the product name
- [x] 3.3 Classify two or more matches as `ambiguous` (covers case-variant rows under case-insensitive collation) and record the name without writing to any candidate
- [x] 3.4 Classify a single match whose barcode is already non-blank as `has_barcode` and leave it untouched
- [x] 3.5 Treat only a single match with a null or empty barcode as applicable

## 4. Applying barcodes with registry consistency

- [x] 4.1 For each applicable row, open a per-row `DB::transaction` so one bad row cannot roll back the whole run
- [x] 4.2 Set `products.barcode` to the file value
- [x] 4.3 Create the matching `barcode_identities` row via `BarcodeIdentityService::reserve()`, deriving `canonical_key` with `BarcodeUtils::canonicalize()`
- [x] 4.4 Do not call `ProductBarcodeAssignmentService::assign()` and do not write any `ProductBarcodeAssignment` audit row
- [x] 4.5 Catch unique-constraint violations on either `products.barcode` or `barcode_identities.canonical_key`, roll back that row only, classify it as `barcode_taken`, and continue the run

## 5. Reporting and dry run

- [x] 5.1 Accumulate counts for applied, `not_found`, `ambiguous`, `has_barcode`, and `barcode_taken`
- [x] 5.2 Print a summary block with a count per category on completion
- [x] 5.3 List skipped rows by product name under their category so the operator can act on them
- [x] 5.4 Short-circuit all writes when `--dry-run` is set, while still performing full matching and producing an identical report

## 6. Tests

- [x] 6.1 Create `Modules/Product/Tests/Feature/BarcodeExportImportCommandTest.php`, following the pattern of `NormalizeProductPurchasePricesCommandTest`
- [x] 6.2 Export writes only barcoded products, with names byte-identical to the stored values (including marker-like prefixes/suffixes and mixed case)
- [x] 6.3 Export reports its row count, and reports zero when no barcodes exist
- [x] 6.4 Restore applies a barcode on an exact single-name match
- [x] 6.5 Restore leaves an existing non-blank barcode untouched and reports it as `has_barcode`
- [x] 6.6 Restore reports an unmatched name as `not_found` without writing
- [x] 6.7 Restore reports a multi-match name as `ambiguous` and writes to none of the candidates
- [x] 6.8 Restore reports a barcode already held by another product as `barcode_taken` and continues to process later rows
- [x] 6.9 Restore writes a `barcode_identities` row alongside the column, with the correctly canonicalized key
- [x] 6.10 Restore writes no `ProductBarcodeAssignment` audit row
- [x] 6.11 A second restore run against the same file modifies nothing and reports every row as `has_barcode`
- [x] 6.12 `--dry-run` produces the full report while writing neither barcode columns nor registry rows
- [x] 6.13 A mixed file exercising every category in one run is fully processed, with each row landing in its expected category
- [x] 6.14 A barcode assigned through `ProductBarcodeAssignmentService` after a restore is rejected as duplicate when it collides with a restored value

## 7. Round-trip verification

- [x] 7.1 Add a test that exports a seeded catalog, clears all barcodes and registry rows, restores from the exported file, and asserts every barcode returns to its original product
- [x] 7.2 Run the focused test suite for both commands and confirm it passes
- [x] 7.3 Document the operator sequence (export and verify count → `migrate:fresh --seed` → data imports → `--dry-run` → restore) in the command help text or descriptions

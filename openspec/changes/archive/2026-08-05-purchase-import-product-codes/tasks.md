## 1. CSV Mapping and Staging

- [x] 1.1 Extend purchase-upload header normalization to recognize `Kode Produk` and supported English product-code aliases as an optional field.
- [x] 1.2 Include the optional product code in controller and queued staging-job row mappings without making it a required upload column.
- [x] 1.3 Add focused mapping/staging tests for populated and absent `Kode Produk` columns.

## 2. Name-First Product Resolution

- [x] 2.1 Extend purchase import product creation to receive the staged imported product code after existing marker parsing.
- [x] 2.2 Make normalized-name database lookup deterministic by selecting the lowest-ID matching product and preserve the selected product code.
- [x] 2.3 For a new normalized name, use a trimmed imported code only when it is available; otherwise retain generated-SKU fallback behavior.
- [x] 2.4 Ensure a conflicting imported code never merges or updates products and causes the new name to receive a generated SKU, including conflicts created earlier in the batch.
- [x] 2.5 Handle a concurrent duplicate-code insert safely by retaining the unique-index guarantee and falling back to generated SKU where the existing transaction pattern permits.

## 3. Verification

- [x] 3.1 Add a purchase-import test proving an unused imported code is persisted for a newly created product.
- [x] 3.2 Add tests proving marker-normalized existing-name matches reuse the first product without changing its code or creating a duplicate product.
- [x] 3.3 Add tests for blank code fallback and for a pre-existing code collision with a distinct product name.
- [x] 3.4 Add a same-batch duplicate-code test modeled on the `DL ES621` distinct-name case, asserting the later product receives a generated SKU.
- [x] 3.5 Run the focused purchase import test suite and record any necessary compatibility adjustments.

## 4. Review Fixes

- [x] 4.1 Retry product creation with a generated SKU when a concurrent worker claims the imported code, scoping the retry to `products.product_code` duplicate-key errors so unrelated database failures still invalidate the invoice group.
- [x] 4.2 Replace the raw `SKU-<md5>` return with a uniqueness-checked helper that appends a suffix until the code is unused, used by the blank-code, conflicting-code, and concurrent-retry paths.
- [x] 4.3 Apply the lowest-ID rule to `preloadProductsForBatch` so the batch cache cannot hand back a higher-ID duplicate ahead of the ordered database lookup.
- [x] 4.4 Add a legacy normalized-name duplicate test asserting the lowest-ID product is reused and neither existing code changes.
- [x] 4.5 Drive the real `StagePurchaseImportRows` mapping path from CSV headers and assert `Kode Produk` reaches `raw_json.kode_produk`.
- [x] 4.6 Add tests for an already-used generated-SKU base and for duplicate-key retry versus non-retryable database errors.

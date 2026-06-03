## 1. Regression Coverage

- [x] 1.1 Add a focused test that simulates sales import page chunk reconstruction with a chunk boundary inside a CSV row and asserts the reconstructed file bytes equal the original file bytes.
- [x] 1.2 Add a focused test using the `JL2915` row shape where the split occurs before `Harga per Unit`, asserting staging preserves or recovers `harga_satuan` as `2400`.
- [x] 1.3 Add or extend mapper tests proving `SalesUploadController`, `StageSalesImportRows`, and `SalesImportService` recognize `Jumlah Per Baris` as the same line total fallback.
- [x] 1.4 Add a service-level mapper regression test proving blank or zero `Harga per Unit` derives from `Jumlah Per Baris / Kuantitas`.

## 2. Binary-Safe Chunk Upload

- [x] 2.1 Replace `Storage::append()` in `SalesUploadController::handleChunkedUpload()` with binary-safe append that writes raw uploaded chunk bytes without inserting separators.
- [x] 2.2 Ensure the temporary chunk file is created, appended, moved to the final sales import path, and cleaned up through the existing storage disk conventions.
- [x] 2.3 Preserve existing successful upload behavior for small non-chunked files, large chunked CSV files, and chunked ZIP files.
- [x] 2.4 Keep batch creation after full file reconstruction and preserve `file_sha256` calculation from the reconstructed final file.

## 3. Sales Mapper Parity

- [x] 3.1 Add `Jumlah Per Baris` and `Jumlah Kena Pajak per Baris` aliases to `SalesImportService::normalizeHeaders()` as the sales line total fallback.
- [x] 3.2 Add missing-unit-price fallback to `SalesImportService::mapCsvRow()` using `line_total / kuantitas` with the same numeric parsing semantics as staging.
- [x] 3.3 Reduce duplicated sales import mapping risk by either extracting shared mapping behavior or making parity explicit with tests around all supported entry points.
- [x] 3.4 Confirm existing rows with non-zero `Harga per Unit` remain authoritative and are not replaced from line total.

## 4. Verification

- [x] 4.1 Run focused tests for sales chunk upload reconstruction and sales import row mapping.
- [x] 4.2 Run focused sales import payment/document reconciliation tests to confirm strict mismatch behavior is preserved.
- [x] 4.3 Run `openspec validate fix-sales-import-chunk-upload-reconstruction --strict`.
- [x] 4.4 Re-upload a representative sales CSV through the import page after implementation and confirm invoice `JL2915` no longer fails due to a missing unit price.

## 1. Upload Validation

- [x] 1.1 Replace the sales-price snapshot endpoint's MIME-derived `mimes:xlsx` rule with validation that requires a valid upload and a case-insensitive `.xlsx` original extension.
- [x] 1.2 Add XLSX-only PhpSpreadsheet structural identification against the temporary upload path, converting identification/read exceptions into an actionable `file` validation error before storage or batch creation.
- [x] 1.3 Preserve the existing authorization, file storage, `sales_price_snapshot` batch creation, redirect, and queue dispatch flow after validation succeeds.

## 2. Regression Coverage

- [x] 2.1 Add a small deterministic real XLSX fixture or test helper with the required `Name*` and `SellPrice` headers for content-based upload validation tests.
- [x] 2.2 Add a feature test proving a structurally valid `.xlsx` upload is accepted when MIME/extension inference reports `application/octet-stream`/`bin`, creating one batch and dispatching `ProcessSalesPriceSnapshotBatch`.
- [x] 2.3 Add feature tests proving a non-XLSX payload renamed to `.xlsx` and a valid XLSX payload with a non-`.xlsx` filename are rejected without batch creation or job dispatch.
- [x] 2.4 Keep the existing authorization, unsupported-file, and normal XLSX upload tests passing, adjusting fake-file setup where necessary so tests exercise actual workbook structure.

## 3. Verification

- [x] 3.1 Run the focused `ProductSalesPriceSnapshotImportUploadTest` suite and relevant workbook-reader tests.
- [x] 3.2 Verify the supplied `TIGA_COMPUTER_ProductExport_12_07_2026.xlsx` passes the revised validation path and remains readable by PhpSpreadsheet without processing production price mutations.

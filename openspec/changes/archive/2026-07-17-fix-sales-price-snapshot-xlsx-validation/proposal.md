## Why

Valid Accurate-exported XLSX workbooks are rejected by the sales-price snapshot upload because the server may detect their MIME type as `application/octet-stream`, causing Laravel's `mimes:xlsx` rule to infer `bin`. The upload must validate the workbook's actual XLSX structure so supported exports are accepted without weakening rejection of arbitrary or unreadable files.

## What Changes

- Replace MIME-derived extension validation for sales-price snapshot uploads with validation of the original `.xlsx` extension plus content-based XLSX identification.
- Continue rejecting renamed, corrupt, encrypted, or otherwise unreadable files before an import batch is created.
- Add regression coverage using a real structurally valid XLSX whose server-detected MIME type is `application/octet-stream`, alongside invalid and disguised-file cases.
- Preserve the existing authorization, batch creation, queue dispatch, and background workbook/header validation behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `product-sales-price-snapshot-import`: Clarify that readable Accurate-style XLSX workbooks are accepted based on their workbook structure even when server MIME detection reports a generic binary type, while non-XLSX content remains rejected.

## Impact

- Product sales-price snapshot request validation in `Modules/Product/Http/Controllers/ProductUploadController.php`.
- Focused Product module upload tests and XLSX test fixtures/builders.
- Existing PhpSpreadsheet identification support; no new dependency, route, database migration, or API change.

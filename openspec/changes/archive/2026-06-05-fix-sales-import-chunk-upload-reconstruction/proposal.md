## Why

Large sales CSV imports uploaded through the sales import page can be reconstructed incorrectly when a CSV row crosses a browser chunk boundary. A confirmed failure occurs on `Sales-2020-Q4.csv` invoice `JL2915`: the first line crosses the 1 MB chunk boundary before `Harga per Unit`, causing the staged row to lose its unit price and fail payment total reconciliation even though the source CSV is valid.

## What Changes

- Reconstruct chunked sales uploads with binary-safe append semantics so the stored upload is byte-for-byte equivalent to the browser-selected file.
- Add regression coverage for a CSV row split inside a sales invoice line, including the `JL2915` shape where the chunk boundary lands before `Harga per Unit`.
- Keep sales import unit-price derivation defensive: when `Harga per Unit` is blank or zero and `Jumlah Per Baris` plus `Kuantitas` are available, derive `harga_satuan` as `Jumlah Per Baris / Kuantitas`.
- Align sales import header normalization and row mapping so web upload, chunked upload, local command, and service-level mapping apply the same `Jumlah Per Baris` fallback behavior.
- Preserve existing strict document/payment total reconciliation; do not loosen mismatch tolerances to mask upload or staging corruption.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `import-batch-performance`: Large import upload and staging must preserve complete CSV content before chunked processing.
- `import-document-total-reconciliation`: Sales imports must derive missing unit price from source line total and quantity before document total reconciliation.

## Impact

- Affected code: `Modules/Sale/Http/Controllers/SalesUploadController.php`, `Modules/Sale/Jobs/StageSalesImportRows.php`, `Modules/Sale/Services/SalesImportService.php`, and focused sales import tests.
- Affected behavior: sales import page uploads for files larger than 1 MB, sales import staging, and sales import mapper parity across web and command entry points.
- No database schema changes are expected.
- No breaking API changes are expected.

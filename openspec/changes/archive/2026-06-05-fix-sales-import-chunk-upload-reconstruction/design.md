## Context

Sales imports can be uploaded through the browser at `/sales/upload`. Files larger than 1 MB are sliced in the browser and sent as chunked requests. The server currently reconstructs the temporary file with `Storage::append($tempPath, $file->get())`.

This is unsafe for arbitrary file chunks because append semantics are text-oriented and can introduce separators between chunks. `Sales-2020-Q4.csv` demonstrates the failure: invoice `JL2915` row 4525 starts at byte 1,048,422 and ends at byte 1,048,656, so it crosses the 1 MB chunk boundary at byte 1,048,576. The boundary lands after `...,25.0,PCS` and before `,2400.0,...`; the staged `raw_json` can therefore lose `Harga per Unit`, causing the valid source invoice total `529000` to be calculated as `469000` and marked invalid with a payment total mismatch.

There is also sales import mapper duplication. `SalesUploadController` and `StageSalesImportRows` recognize `Jumlah Per Baris` as `line_total` and can derive missing unit price from `line_total / kuantitas`, while `SalesImportService` does not currently expose the same alias or fallback. That leaves non-page or service-level paths vulnerable to the same staged row shape.

## Goals / Non-Goals

**Goals:**

- Reconstruct chunked sales uploads byte-for-byte so CSV rows cannot be split or altered by server-side append behavior.
- Preserve current browser chunking behavior for large sales uploads while making it safe for CSV and ZIP uploads.
- Keep strict invoice/payment reconciliation; valid rows should stage correctly, and truly unreconciled invoices should still fail.
- Apply the `Jumlah Per Baris / Kuantitas` unit-price fallback consistently across sales import mapping paths.
- Cover the `JL2915` boundary shape with focused regression tests.

**Non-Goals:**

- No change to purchase upload behavior unless an equivalent chunked upload defect is found there.
- No database schema changes.
- No relaxation of payment total, source total, or document total mismatch tolerances.
- No automatic repair of previously corrupted staged import batches; users can re-upload after the fix.

## Decisions

### Use binary-safe append for chunk reconstruction

The server should append uploaded chunk bytes using a filesystem handle opened in append-binary mode, or an equivalent Laravel filesystem operation that does not add separators or transform content. The final stored file must equal the concatenation of browser-provided chunks exactly.

Alternatives considered:

- Keep `Storage::append()`: rejected because it can alter content at chunk boundaries.
- Increase chunk size: rejected because it only reduces the probability of splitting a CSV row and does not eliminate corruption.
- Disable chunking entirely: acceptable as a temporary operational workaround, but not the preferred product behavior for large historical files.

### Verify reconstruction before staging

The chunk upload path already computes `file_sha256` after reconstruction. The implementation should preserve that and add test coverage around byte equality for reconstructed uploads. If practical, the final chunk response can continue to create the batch only after the reconstructed file exists and can be read by `League\Csv\Reader`.

Alternatives considered:

- Add complex client-side checksum negotiation: not needed for this fix; server-side binary append plus existing hash storage is sufficient for the known defect.

### Keep staging fallback for missing sales unit price

When `Harga per Unit` is blank or parses as zero, and `Kuantitas > 0` plus `Jumlah Per Baris > 0` are available, the sales mapper should derive `harga_satuan = Jumlah Per Baris / Kuantitas` before staging. This preserves the existing regression expectation for `JL2915` and supports historical CSVs that omit unit price but include line total.

Alternatives considered:

- Mark rows invalid when unit price is missing: rejected because historical source exports can be recovered safely from line total and quantity.
- Use product master sale price: rejected because current product price may differ from the historical transaction price.

### Unify sales import mapping semantics

The service-level normalizer and mapper should recognize the same sales import columns as the upload/controller and staging job paths, including `Jumlah Per Baris` and `Jumlah Kena Pajak per Baris` as `line_total`. Either the duplicated mapping should be refactored into one shared component, or the existing duplicated methods should be updated with focused tests to prove parity.

Alternatives considered:

- Only patch the chunk upload bug: rejected because the service mapper remains inconsistent and can recreate the same staged-row failure through another entry point.

## Risks / Trade-offs

- Binary append path differs across local and remote disks → Use Laravel storage paths already used by the import controller and test with the configured local disk path.
- Previously staged corrupted batches remain invalid → Document that users should delete or ignore those batches and re-upload source CSVs after the fix.
- Mapper refactor could touch broader import behavior → Keep the first implementation narrow, with parity tests for `JL2915`, normal rows with existing unit price, and missing unit price recovered from line total.
- ZIP uploads may use the same chunk route → Treat chunk bytes opaquely; binary-safe reconstruction benefits ZIP files as well and should not inspect content until all chunks are assembled.

## Migration Plan

1. Deploy the binary-safe chunk reconstruction and sales mapper parity changes.
2. Run focused tests for chunk upload reconstruction and sales row mapping.
3. Re-upload affected historical sales CSVs through the import page.
4. If a deployment needs rollback, revert the application code and avoid chunked page upload for large sales CSVs until the fix is restored.

## Open Questions

- Should the import detail page surface a warning for batches created before this fix if their file size exceeded the chunk threshold? This is useful but not required for the core fix.

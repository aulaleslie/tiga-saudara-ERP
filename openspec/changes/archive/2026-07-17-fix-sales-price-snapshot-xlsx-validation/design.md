## Context

The sales-price snapshot endpoint currently uses Laravel 10's `mimes:xlsx` validation rule. In this framework version the rule compares `UploadedFile::guessExtension()` with `xlsx`; that guessed extension is derived from server-side MIME detection rather than the user-visible filename. The supplied Accurate workbook is a valid Office Open XML package, passes ZIP integrity checks, is identified and loaded by PhpSpreadsheet as `Xlsx`, and contains the required headers, but the host's MIME database reports `application/octet-stream`, which Symfony maps to the inferred extension `bin`.

The existing upload acceptance test uses an `UploadedFile::fake()` object that reports an explicitly supplied XLSX MIME type. It therefore verifies the happy-path controller flow but does not reproduce MIME detection for a real Accurate-style workbook.

## Goals / Non-Goals

**Goals:**

- Accept genuine Accurate-style `.xlsx` workbooks even when the host reports a generic binary MIME type.
- Reject arbitrary files renamed to `.xlsx` before creating or dispatching an import batch.
- Keep validation aligned with the PhpSpreadsheet reader used by the queued processor.
- Add regression tests that exercise real workbook bytes and the generic-MIME case.

**Non-Goals:**

- Supporting `.xls`, `.xlsm`, `.ods`, CSV, or other spreadsheet formats on this endpoint.
- Changing workbook header rules, price processing, owner resolution, authorization, or batch lifecycle behavior.
- Moving the complete workbook import into the synchronous HTTP request.
- Adding a new spreadsheet or MIME-detection dependency.

## Decisions

### 1. Validate the client filename extension and XLSX package structure separately

The upload validation will first require a valid uploaded file whose original extension, compared case-insensitively, is exactly `xlsx`. It will then use PhpSpreadsheet's XLSX reader identification/capability check against the upload's temporary path, restricted to the XLSX reader.

This separates the user-facing format contract from unreliable host MIME inference and confirms that the bytes contain the workbook parts expected by the same library that processes the batch. The validator will catch reader/ZIP exceptions and return the normal field validation error without storing the file or creating a batch.

Alternatives considered:

- Add `bin` to `mimes`: rejected because it authorizes any content whose inferred type is generic binary and does not prove workbook structure.
- Add `application/octet-stream` to `mimetypes`: rejected for the same overly broad acceptance and because MIME detection is the source of the false negative.
- Trust only `getClientOriginalExtension()`: rejected because an arbitrary file can be renamed to `.xlsx`.
- Fully load the workbook during request validation: rejected because it duplicates the queued job's heavier parsing and increases request memory and latency. Reader identification is sufficient for upload-format gating; header and row validation remain in the job.

### 2. Keep structural upload gating synchronous and semantic validation asynchronous

The HTTP request will verify only that the upload is a readable XLSX container. Required headers, worksheet content, encrypted/corrupt edge cases encountered during full loading, and row semantics will continue to be handled by `ProcessSalesPriceSnapshotBatch` with its existing failed-batch reporting.

This boundary prevents obvious disguised uploads from creating batches while retaining the established queued workflow for work proportional to workbook size.

### 3. Test with real XLSX bytes rather than MIME-stubbed fake content

Regression coverage will submit a small valid workbook whose uploaded object/server inspection resolves to `application/octet-stream`, or construct an `UploadedFile` around a fixture with that observed behavior. Tests will assert that the batch is created and the job dispatched. Separate cases will assert rejection of a non-XLSX file renamed with `.xlsx` and of a valid workbook with a non-`.xlsx` filename.

The test fixture should be minimal and deterministic; the supplied production export need not be committed in full. Existing tests for authorization and the standard XLSX MIME path remain valuable and should continue to pass.

## Risks / Trade-offs

- **[PhpSpreadsheet identification may accept a package that later fails full workbook loading]** → Keep the queued reader's existing exception handling and failed-batch status; identification is an early format gate, not a replacement for semantic parsing.
- **[A fixture's MIME result can vary across operating-system MIME databases]** → Assert behavior through a real uploaded file and explicitly cover the observed generic-MIME path without making the test depend solely on one host's `fileinfo` mapping.
- **[Synchronous ZIP inspection adds upload latency]** → Restrict identification to the XLSX reader and avoid loading worksheets or styles in the request.
- **[Client filenames are untrusted]** → Treat the extension only as one condition and require independent content-based identification.

## Migration Plan

1. Deploy the validator adjustment and focused regression tests with no schema or configuration changes.
2. Verify the supplied Accurate export passes upload validation in the target environment and reaches the existing queued batch processor.
3. Monitor upload validation errors and failed sales-price snapshot batches after deployment.

Rollback is limited to restoring the previous validation rule; no stored data or database migration requires reversal.

## Open Questions

None. The endpoint remains XLSX-only, and PhpSpreadsheet is already the authoritative workbook reader for this workflow.

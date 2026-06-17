## Context

Sales import currently stages CSV rows, processes pending rows in row-number chunks, groups each chunk by source invoice and product-name owner, reconciles payment at source-invoice scope, allocates document adjustments and settlement to owner groups, then creates one sale per owner group. Recent import changes made CSV `Total` and `Status Hari Ini` authoritative for sales settlement and added canonical totals, but the observed invalid rows show remaining gaps:

- split-owner invoices can reconcile at source-invoice scope but fail after discount allocation because rounded owner totals no longer sum exactly to the authoritative total;
- source invoices with high-precision `harga_satuan`, `pajak`, or `Diskon` can differ from source `Total` by one cent or a few cents even when `Lunas` settlement fields are exact;
- invoice-aware chunking only extends the last contiguous invoice in a chunk, so non-contiguous rows with the same `no_faktur` may still be reconciled without complete source-invoice context;
- loading complete source invoices through `raw_json->no_faktur` can require repeated unindexed JSON scans across pending staged rows during large imports;
- zipped sales uploads currently copy extracted CSV content through `file_get_contents`, which can load the entire extracted file into PHP memory before staging starts;
- a visible single-row invoice such as `JL.2026.2146` mathematically reconciles, so a precision-drift failure for that payload likely means older code, hidden same-invoice rows, or incomplete invoice grouping.

The fix should stay inside sales import reconciliation and preserve existing product-name owner routing, no-stock import behavior, CSV tag metadata, and strict rejection of true source mismatches.

## Goals / Non-Goals

**Goals:**

- Reconcile sales imports using complete source invoice row sets before owner sale creation.
- Produce canonical two-decimal owner sale totals whose sum equals the authoritative source invoice total.
- Use the same canonical owner total for sale header totals, payment status, final settlement validation, and payment rows.
- Preserve strict validation for conflicting repeated fields, over-settlement, missing source totals where required, and material source-total mismatches.
- Preserve large-upload performance by loading complete source invoices through indexed staged invoice metadata instead of JSON-field scans.
- Avoid whole-file memory copies while preparing uploaded ZIP/CSV files for asynchronous staging.
- Add focused regression coverage for the `prompt.txt`, `prompt1.txt`, and `prompt2.txt` shapes.

**Non-Goals:**

- No historical batch repair or automatic reprocessing.
- No purchase import behavior change unless required to keep shared helpers backward-compatible.
- No change to product-name owner routing, CSV tag metadata behavior, stock mutation behavior, or report screens.
- No broad increase of source-total precision tolerances.

## Decisions

### Decision 1: Load complete pending source invoices, not only contiguous chunk tails

Sales import chunk processing should select a bounded row-number window, extract the distinct `no_faktur` values in that window, then load every pending row in the batch for those invoices before grouping and processing. This makes the unit of reconciliation the source invoice, even when rows are not contiguous in the uploaded CSV.

Rationale: payment and document adjustment fields are invoice-scoped. Processing a partial invoice can turn valid rows into false `Precision drift exceeds absolute limit` or settlement mismatch errors.

Alternative considered: only extend the chunk when the last row's invoice continues contiguously. This is the current behavior and fails if the same source invoice appears later in the file.

### Decision 1a: Stage and index source invoice numbers for complete-invoice loading

Sales import staging should persist a normalized source invoice number column on `sales_import_rows`, populated from mapped `no_faktur`, and index it with `batch_id` and `status`. `SalesImportService::processBatch()` should use that indexed column when loading all pending rows for invoices selected by the initial row-number window. Existing rows without the staged key may fall back to `raw_json['no_faktur']` only for compatibility, but new imports should not rely on JSON-path predicates for the hot path.

Rationale: invoice-complete loading is correct but can be expensive if each chunk expansion performs `whereIn('raw_json->no_faktur', ...)` against a large pending row set. An indexed staged key keeps the correctness fix viable for large sales uploads.

Alternative considered: keep the JSON predicate and rely on the row-number chunk to bound work. That still leaves repeated scans over pending rows as the batch drains and can become the dominant cost for large historical files.

### Decision 1b: Keep upload preparation streaming-friendly

ZIP handling should move or stream-copy the extracted CSV into storage rather than reading the entire extracted file into memory. Header sampling may remain bounded to the first few kilobytes.

Rationale: staging is already asynchronous and chunked, but the upload controller can still spike memory before the staging job starts when a zipped CSV is extracted and copied with `file_get_contents`.

Alternative considered: only document a maximum ZIP size. That does not address existing valid historical uploads whose extracted CSV is large but processable by the staged importer.

### Decision 2: Allocate document adjustments and source-total drift as exact two-decimal owner totals

The source-invoice phase should resolve document `Diskon`, `Biaya Pengiriman`, and accepted source-total precision adjustment once, allocate them across owner groups, round owner canonical totals to two decimals, and assign any rounding remainder deterministically to the largest positive owner group.

Rationale: generated `sales.total_amount`, `paid_amount`, `due_amount`, and payment rows are two-decimal money values. Allowing fractional allocation leftovers to survive until later validation can make owner totals sum to `source_total + 0.01` and trigger false over-settlement.

Alternative considered: leave fractional allocations and increase final tolerance. That imports some cases but hides real accounting mismatches and keeps two different total models alive.

### Decision 3: Treat canonical owner totals as the persistence contract

`processInvoiceGroup()` should consume the canonical total produced by source-invoice reconciliation and use it for sale header `total_amount`, payment validation, payment status, cash payment rows, deduction payment rows, and the final paid-plus-due check. Detail rows should continue preserving imported row-level DPP/tax values; small accepted differences remain document-level reconciliation artifacts.

Rationale: the source-invoice phase is where authoritative settlement is proven. Recomputing a separate owner total later can reject invoices that already reconciled under the import contract.

Alternative considered: adjust detail unit prices or tax amounts to force details to sum to the canonical total. That would invent row-level source data and could distort product/tax reporting.

### Decision 4: Keep precision drift narrow and observable

Accepted precision drift remains bounded by existing sales precision limits and requires source settlement fields to reconcile to source `Total`. Accepted drift should continue producing structured logs with invoice, batch, row IDs, recomputed total, source total, and drift amount.

Rationale: this path exists for historical export precision artifacts, not for data cleanup. Observability helps distinguish accepted precision differences from exact imports.

Alternative considered: always trust `Total` for `Lunas` invoices. That would simplify implementation but risks importing corrupted source files whose line composition materially disagrees with settlement fields.

## Risks / Trade-offs

- [Risk] Loading every pending row for invoices in the current window can make a chunk larger than the target size. -> Mitigation: keep the initial window bounded, load expanded rows through an indexed invoice key, log actual chunk size, and process by invoice batches as today.
- [Risk] Canonical sale totals may differ by cents from the sum of sale detail subtotals. -> Mitigation: limit the difference to accepted invoice-level precision adjustment and keep detail values as imported.
- [Risk] Shared allocator changes could affect purchase imports. -> Mitigation: add sales-specific canonical-total handling where possible; if shared helpers change, run focused purchase import allocation tests.
- [Risk] A new staged invoice key requires compatibility for already-staged pending rows. -> Mitigation: make the column nullable and fall back to JSON extraction only when the staged key is missing.
- [Risk] Previously invalid historical batches remain invalid until reprocessed. -> Mitigation: document that the change affects future processing and manual re-upload/reprocess workflows only.

## Migration Plan

Add a nullable, indexed source invoice number column to staged sales import rows. Deploy the migration before the code path that writes and queries the new key. Existing staged pending rows remain processable through the compatibility fallback; newly uploaded rows populate the key during staging. Rollback should drop the new index/column after application-code rollback if needed.

## Open Questions

- Should the import detail UI eventually surface accepted precision drift notes, or are structured logs enough for this fix?
- If non-contiguous duplicate `no_faktur` rows are intentional separate invoices in a source file, should import eventually support a stronger grouping key? This change preserves the current `no_faktur` invoice contract.

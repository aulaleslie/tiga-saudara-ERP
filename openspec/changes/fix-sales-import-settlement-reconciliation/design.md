## Context

Sales import currently stages CSV rows, processes pending rows in row-number chunks, groups each chunk by source invoice and product-name owner, reconciles payment at source-invoice scope, allocates document adjustments and settlement to owner groups, then creates one sale per owner group. Recent import changes made CSV `Total` and `Status Hari Ini` authoritative for sales settlement and added canonical totals, but the observed invalid rows show remaining gaps:

- split-owner invoices can reconcile at source-invoice scope but fail after discount allocation because rounded owner totals no longer sum exactly to the authoritative total;
- source invoices with high-precision `harga_satuan`, `pajak`, or `Diskon` can differ from source `Total` by one cent or a few cents even when `Lunas` settlement fields are exact;
- invoice-aware chunking only extends the last contiguous invoice in a chunk, so non-contiguous rows with the same `no_faktur` may still be reconciled without complete source-invoice context;
- a visible single-row invoice such as `JL.2026.2146` mathematically reconciles, so a precision-drift failure for that payload likely means older code, hidden same-invoice rows, or incomplete invoice grouping.

The fix should stay inside sales import reconciliation and preserve existing product-name owner routing, no-stock import behavior, CSV tag metadata, and strict rejection of true source mismatches.

## Goals / Non-Goals

**Goals:**

- Reconcile sales imports using complete source invoice row sets before owner sale creation.
- Produce canonical two-decimal owner sale totals whose sum equals the authoritative source invoice total.
- Use the same canonical owner total for sale header totals, payment status, final settlement validation, and payment rows.
- Preserve strict validation for conflicting repeated fields, over-settlement, missing source totals where required, and material source-total mismatches.
- Add focused regression coverage for the `prompt.txt`, `prompt1.txt`, and `prompt2.txt` shapes.

**Non-Goals:**

- No database schema changes.
- No historical batch repair or automatic reprocessing.
- No purchase import behavior change unless required to keep shared helpers backward-compatible.
- No change to product-name owner routing, CSV tag metadata behavior, stock mutation behavior, or report screens.
- No broad increase of source-total precision tolerances.

## Decisions

### Decision 1: Load complete pending source invoices, not only contiguous chunk tails

Sales import chunk processing should select a bounded row-number window, extract the distinct `no_faktur` values in that window, then load every pending row in the batch for those invoices before grouping and processing. This makes the unit of reconciliation the source invoice, even when rows are not contiguous in the uploaded CSV.

Rationale: payment and document adjustment fields are invoice-scoped. Processing a partial invoice can turn valid rows into false `Precision drift exceeds absolute limit` or settlement mismatch errors.

Alternative considered: only extend the chunk when the last row's invoice continues contiguously. This is the current behavior and fails if the same source invoice appears later in the file.

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

- [Risk] Loading every pending row for invoices in the current window can make a chunk larger than the target size. -> Mitigation: keep the initial window bounded, log actual chunk size, and process by invoice batches as today.
- [Risk] Canonical sale totals may differ by cents from the sum of sale detail subtotals. -> Mitigation: limit the difference to accepted invoice-level precision adjustment and keep detail values as imported.
- [Risk] Shared allocator changes could affect purchase imports. -> Mitigation: add sales-specific canonical-total handling where possible; if shared helpers change, run focused purchase import allocation tests.
- [Risk] Previously invalid historical batches remain invalid until reprocessed. -> Mitigation: document that the change affects future processing and manual re-upload/reprocess workflows only.

## Migration Plan

No migration is required. Deploy the code and tests, then re-run or re-upload affected sales import batches. Rollback is application-code rollback only; no persisted data transformation is needed.

## Open Questions

- Should the import detail UI eventually surface accepted precision drift notes, or are structured logs enough for this fix?
- If non-contiguous duplicate `no_faktur` rows are intentional separate invoices in a source file, should import eventually support a stronger grouping key? This change preserves the current `no_faktur` invoice contract.

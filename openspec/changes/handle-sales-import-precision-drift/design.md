## Context

Sales import reconciliation currently computes an adjusted document total from the staged rows, document discount, and shipping, then asks `ImportPaymentSummaryResolver` to verify that source `Total`, `Pembayaran`, deductions, and outstanding balances reconcile. The resolver accepts up to `1.00` source-total drift, which protects against ordinary floating-point noise while still rejecting likely upload corruption or malformed rows.

Invoice `TN.20211796` exposes a different historical source shape. The two exported rows contain usable quantities, high-precision DPP unit prices, explicit tax amounts, a repeated fixed document discount, `Status Hari Ini = Lunas`, near-zero `Sisa Tagihan Hari Ini`, and `Pembayaran` equal to source `Total`. Recomputing from exported row values produces `126,964,597.07`, while source `Total` and `Pembayaran` are `126,964,600.00`. The `2.93` gap is too large for the current absolute tolerance but small enough to look like source-system precision drift rather than missing line data.

This change must stay narrower than the recent chunk-upload reconstruction fix. It should not loosen validation enough to hide corrupted staging rows, blank unit prices without line-total fallback, conflicting repeated fields, or real commercial mismatches.

## Goals / Non-Goals

**Goals:**

- Allow narrowly bounded historical sales source-total precision drift when settlement fields independently reconcile to the source total.
- Keep exact/current behavior for purchase imports unless an equivalent purchase source shape is proven later.
- Keep strict failure for large mismatches, inconsistent repeated source fields, incomplete row data, and payment/outstanding mismatches.
- Make accepted drift observable through logs and, where practical, the import row/batch processing context.
- Cover the known `TN.20211796` shape and negative boundaries with focused tests.

**Non-Goals:**

- No broad increase of global reconciliation tolerance for all imports.
- No change to browser chunk upload reconstruction.
- No automatic repair of already imported or already invalid historical batches.
- No database schema change solely to store drift metadata.
- No reinterpretation of document `Diskon` as line discount or `Jumlah Pemotongan` as price discount.

## Decisions

### Decision 1: Gate precision drift to sales source-total reconciliation only

Add an explicit sales-import path that can reconcile using source `Total` as the authoritative document total when all of these conditions hold:

- The invoice is being processed by the sales importer.
- The ordinary adjusted document total fails source-total reconciliation only because the source total differs from the recomputed total.
- Source `Total` is present and positive.
- `Pembayaran + Jumlah Pemotongan + preferred outstanding` reconciles to source `Total`.
- Repeated source fields remain internally consistent.
- The absolute drift is small and the relative drift is tiny.

Rationale: the failing invoice has a row-total precision problem, not a settlement problem. Gating this inside the sales import flow avoids changing purchase behavior or making `ImportPaymentSummaryResolver` silently more permissive everywhere.

Alternatives considered:

- Raise `SOURCE_TOTAL_TOLERANCE` globally: rejected because it weakens purchase and sales validation for every source shape.
- Disable source-total validation when payment fields reconcile: rejected because a corrupted line can still have repeated payment fields from the source export.
- Recompute tax from `tarif_pajak` instead of CSV `pajak`: rejected as the default because explicit CSV tax is a stronger source field and changing tax interpretation can affect many invoices.

### Decision 2: Use dual absolute and relative drift bounds

The sales precision-drift path should require both:

- A small absolute cap suitable for IDR historical rounding noise.
- A tiny relative cap based on source `Total`.

The implementation can start with conservative constants, for example an absolute cap around `5.00` and a relative cap around `0.001%`, and tests should prove `TN.20211796` passes while a nearby larger mismatch fails.

Rationale: absolute tolerance alone is unsafe for tiny invoices, while relative tolerance alone can admit too much drift on large invoices. A dual gate matches the observed failure without turning strict reconciliation into approximate import.

Alternatives considered:

- Absolute-only tolerance: rejected because `5.00` on a `10.00` invoice is not acceptable.
- Relative-only tolerance: rejected because a large invoice could allow a material rupiah mismatch.
- User-configurable tolerance in settings: rejected for this fix because it adds operational complexity and no known user workflow needs per-tenant tuning.

### Decision 3: Persist document amounts from the authoritative source total only after drift acceptance

When the drift path accepts an invoice, the generated sale header should use source `Total` as the invoice-level total for settlement consistency. The small drift should be assigned deterministically at the header level rather than altering product quantities, product unit prices, or explicit tax amounts.

Rationale: changing individual lines would invent detail-level source data. The source document total and settlement fields are the stronger invoice-level truth for this historical shape, while sale details should continue reflecting exported product rows.

Alternatives considered:

- Adjust the largest line subtotal: rejected because it changes product-level valuation without source evidence.
- Adjust tax amount: rejected because the CSV provides explicit tax values and tax reporting may depend on them.
- Import at recomputed total and ignore source `Pembayaran`: rejected because it would turn a source-paid invoice into an overpaid or mismatched document.

### Decision 4: Log accepted precision drift

Accepted drift should emit a structured log entry containing batch ID, invoice number, source total, recomputed total, drift amount, and row IDs. If an existing row processing context can carry a non-fatal note without schema changes, include the same detail there; otherwise logs are sufficient for the first implementation.

Rationale: this is intentionally an exception path. Operators and future maintainers need to tell precision drift apart from exact reconciliation.

Alternatives considered:

- Add columns to import rows for reconciliation metadata: rejected for now because this is narrow and schema churn is not needed.
- No observability: rejected because accepted mismatches deserve traceability.

## Risks / Trade-offs

- [Risk] A corrupt row with a small mismatch could be accepted. -> Require complete row data, consistent repeated fields, settlement reconciliation to source total, and both absolute and relative drift caps.
- [Risk] Header total may differ slightly from summed sale detail subtotals. -> Keep the drift small, deterministic, logged, and limited to invoices where source settlement proves the authoritative total.
- [Risk] Future purchase imports may show the same pattern. -> Keep this scoped to sales now; add purchase only with a separate proven case and tests.
- [Risk] Constants may be too strict or too loose. -> Encode the known `2.93` case and negative boundary cases so future adjustment is deliberate.

## Migration Plan

1. Deploy the sales precision-drift reconciliation path with focused tests.
2. Re-run the affected sales import batch or re-upload the historical source CSV.
3. Review logs for accepted precision drift entries.
4. Rollback by reverting the application change; no schema rollback is required.

## Open Questions

- Should accepted precision-drift notes be surfaced in the import detail UI, or are structured logs enough for the first release?
- Should the exact drift constants be configurable after observing more historical sales exports?

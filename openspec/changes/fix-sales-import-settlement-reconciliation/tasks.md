## 1. Regression Coverage

- [x] 1.1 Add a focused sales import test for `JL.2025.14721` shape: split owner rows, repeated document `Diskon`, `Lunas`, and source invoice total exactly reconciling after discount.
- [x] 1.2 Add a focused sales import test for `JL.2025.24026` shape: split owner rows, fractional discount allocation, and `settlement exceeds owner document totals` must not occur.
- [x] 1.3 Add a focused sales import test for `JL.2025.25893` shape: exact one-cent source-total artifact with split owner rows imports as paid when status mapping makes CSV `Total` authoritative.
- [x] 1.4 Add a focused sales import test for `JL.2026.2146` shape: single-row `Lunas` invoice whose DPP plus tax equals CSV `Total` imports without precision drift failure.
- [x] 1.5 Add a focused sales import test where same-`no_faktur` pending rows are non-contiguous by `row_number` and must reconcile as one source invoice.

## 2. Complete Source Invoice Loading

- [x] 2.1 Update `SalesImportService::processBatch()` chunk loading to collect distinct invoice numbers from the initial pending row window.
- [x] 2.2 Load all pending rows for the selected invoice numbers ordered by `row_number`, replacing the current last-contiguous-invoice extension logic.
- [x] 2.3 Preserve bounded processing by keeping the initial row window size and existing invoice transaction batching, and update chunk logging to show initial rows, actual rows, invoice count, and any expanded rows.
- [x] 2.4 Ensure skipped, processed, and invalid rows from prior iterations are not reloaded when completing invoice sets.

## 3. Canonical Owner Totals

- [x] 3.1 Make sales source-invoice reconciliation produce two-decimal canonical owner totals after document discount, shipping, and accepted source-total precision adjustment.
- [x] 3.2 Ensure document adjustment allocation assigns two-decimal rounding remainders deterministically so owner canonical totals sum to the source-invoice adjusted total.
- [x] 3.3 Ensure accepted source-total precision drift is allocated to owner canonical totals before settlement allocation.
- [x] 3.4 Keep zero-total owner groups valid and prevent settlement allocation from assigning cash or deduction to zero-total owner documents.

## 4. Settlement And Persistence

- [x] 4.1 Ensure `ImportSettlementAllocator` receives canonical owner totals and never allocates cash plus deduction greater than an owner canonical total.
- [x] 4.2 Ensure `processInvoiceGroup()` persists `Sale::total_amount` from the canonical owner total passed by source-invoice reconciliation.
- [x] 4.3 Ensure final group settlement validation compares paid, deduction, and due against the same canonical owner total used for sale header persistence.
- [x] 4.4 Ensure cash payment rows and `Jumlah Pemotongan` payment rows are created from the allocated settlement components and reconcile to each generated sale.
- [x] 4.5 Preserve imported sale detail DPP, tax, quantity, dispatch detail, product, and tag metadata behavior without forcing detail subtotals to equal adjusted canonical header totals.

## 5. Guardrails And Observability

- [x] 5.1 Preserve strict failures for conflicting repeated payment or adjustment fields within the complete source invoice.
- [x] 5.2 Preserve existing sales precision drift absolute and relative limits for material source-total mismatches.
- [x] 5.3 Keep or improve structured logging for accepted precision drift with batch ID, invoice number, row IDs, source total, recomputed total, and drift amount.
- [x] 5.4 Add diagnostic logging for reconciliation failures that includes owner gross totals, allocated adjustments, canonical totals, and settlement components.

## 6. Verification

- [x] 6.1 Run the focused sales import test file(s) covering payment ledger, split-owner allocation, document total reconciliation, and the new prompt-derived regressions.
- [x] 6.2 Run focused purchase import allocation/payment tests if any shared allocator or payment resolver behavior changes.
- [x] 6.3 Run `php artisan test` with focused filters for sales import and import payment resolver coverage.
- [x] 6.4 Confirm OpenSpec status shows implementation tasks tracked for `fix-sales-import-settlement-reconciliation`.

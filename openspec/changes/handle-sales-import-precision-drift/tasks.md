## 1. Regression Coverage

- [ ] 1.1 Add a focused sales import regression test for invoice `TN.20211796` shape where recomputed adjusted total is `126,964,597.07`, source `Total` and `Pembayaran` are `126,964,600.00`, `Status Hari Ini` is `Lunas`, and near-zero outstanding is accepted.
- [ ] 1.2 Assert the accepted sale uses source `Total` for `total_amount`, has `paid_amount + due_amount` reconciled to source `Total`, and creates active payment rows reconciled to source `Total`.
- [ ] 1.3 Add a negative sales import test where the source-total drift exceeds the configured absolute precision-drift limit and the invoice remains invalid.
- [ ] 1.4 Add a negative sales import test where drift is within the source-total drift limit but `Pembayaran + Jumlah Pemotongan + outstanding` does not reconcile to source `Total`.
- [ ] 1.5 Add or preserve a purchase import test proving purchase source-total drift outside the existing purchase tolerance still fails.

## 2. Precision Drift Resolution

- [ ] 2.1 Add a sales-only reconciliation path that can evaluate source-total precision drift without raising the global `ImportPaymentSummaryResolver` tolerance for all callers.
- [ ] 2.2 Define explicit absolute and relative sales precision-drift limits and apply both before accepting source `Total` as authoritative.
- [ ] 2.3 Require consistent repeated source fields and successful settlement reconciliation to source `Total` before accepting drift.
- [ ] 2.4 Return or carry enough reconciliation metadata for the sales importer to distinguish exact reconciliation from accepted precision drift.

## 3. Sales Import Integration

- [ ] 3.1 Update `SalesImportService::processSourceInvoice` to retry or route source-total reconciliation through the sales precision-drift path only after ordinary adjusted-total reconciliation fails.
- [ ] 3.2 When precision drift is accepted, use source `Total` for the invoice-level source total and owner group totals needed for settlement allocation.
- [ ] 3.3 Keep sale detail quantities, unit prices, explicit tax amounts, document discount, and shipping calculations based on the exported row data.
- [ ] 3.4 Ensure split-owner sales invoices still allocate settlement components without negative paid, deduction, or due amounts when precision drift is accepted.

## 4. Observability

- [ ] 4.1 Emit a structured log entry when precision drift is accepted, including batch ID, invoice number, source total, recomputed adjusted total, drift amount, and row IDs.
- [ ] 4.2 Ensure ordinary exact reconciliation does not emit the precision-drift log entry.
- [ ] 4.3 Ensure failed mismatches keep the existing invalid row status and payment/document mismatch error message.

## 5. Verification

- [ ] 5.1 Run focused unit tests for the payment summary or new precision-drift resolver.
- [ ] 5.2 Run focused sales import feature tests covering accepted drift and negative boundaries.
- [ ] 5.3 Run the relevant purchase import feature test proving purchase behavior was not broadened.
- [ ] 5.4 Run `openspec status --change handle-sales-import-precision-drift` and confirm the change remains apply-ready.

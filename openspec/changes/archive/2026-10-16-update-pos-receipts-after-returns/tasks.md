# Tasks

## 1. Establish shared projection and financial evidence

- [x] 1.1 Add focused fixtures for completed cash returns, replacements, and receipt-group provenance using existing receipt/reprint test helpers; verify fixtures expose the current stale quantity, serial, and paid-amount behavior.
- [x] 1.2 Verify debt-first execution and persisted refund evidence with the agreed Rp300,000 total / Rp200,000 paid examples for Rp50,000 and Rp150,000 returns; make the smallest transactional refund-settlement correction if needed, and verify actual refund evidence is respectively zero and Rp50,000 while source debt becomes Rp50,000 and zero.
- [x] 1.3 Extract or extend a shared read-only completed receipt builder from the existing projection while retaining original snapshot retrieval; verify no-return current-price reprints and explicit historical receipts with focused existing tests.

## 2. Project current items and completed return history

- [x] 2.1 Load completed, non-reversed returns and execution provenance for the entire checkout group with scoped eager loading and deterministic order; verify draft, pending, approval-only, incomplete, rejected, cancelled, archived, reversed, and deleted cases do not contribute completed return history or corresponding item adjustments.
- [x] 2.2 Project remaining cash-return quantities and authoritative current charges without subtracting already-applied Sale corrections again; verify partial returns, fully omitted lines, a valid all-items-returned receipt, and unrelated same-product rows.
- [x] 2.3 Recompute remaining packed-unit and bundle component quantities using original factors/composition and customer-line grouping; verify split-owner allocations reduce a customer bundle once and component rows never add charges.
- [x] 2.4 Project effective serials through completed replacement chains and later cash returns while retaining unaffected and informational component serials; verify A-to-B-to-C lineage, cash return of C, non-serial replacements, and parent-only bundle replacements.
- [x] 2.5 Preserve one customer-facing quantity/value for cross-owner replacement by following proven replacement Sale/dispatch targets; verify replacement value remains included exactly once in line and receipt totals.
- [x] 2.6 Build grouped return-history payloads with reference, completion date/status, items, quantities, resolutions, value credits, debt offsets, actual refunds, and serial pairs; verify multiple mixed completed returns appear once and fully returned items remain represented.

## 3. Present reconciled current payments

- [x] 3.1 Replace historical tender-as-paid with retained current payments and live debt, reconciling method rows to the current paid total and excluding original change from current-state return receipts; verify debt-first examples, fully paid refunds, later global payments, and multiple payment methods.
- [x] 3.2 Extend safe refusal to ambiguous quantity, serial, or financial evidence while preserving historical access and read-only behavior; verify inconsistent evidence produces an actionable response without a successful print log or financial mutation.

## 4. Connect print paths and render receipts

- [x] 4.1 Route ordinary completed checkout Print, transaction Print/Reprint, checkout Reprint, and global-payment Reprint through the common builder; verify equivalent receipt payloads and preserve endpoint-specific permissions, setting isolation, and successful PRINT/REPRINT audit types.
- [x] 4.2 Preserve explicit historical snapshot output and draft/loaded previews; verify original quantities, serials, tender/change, historical labeling, and existing customer/transaction-time display with focused tests.
- [x] 4.3 Render remaining items, current financial rows, and the return-history section in the existing receipt Blade view with Indonesian labels; verify fully returned lines are absent from main items and serial/refund/debt details render below.

## 5. Focused integration verification

- [x] 5.1 Run only the affected named receipt/reprint and return-execution test files or focused php artisan test filters, including PosReprintCurrentMoneyTest and relevant POSTransactionReceiptReprintTest/POSReceiptGenerationTest cases; record commands/results and resolve affected failures. Do not run or require the full test suite.
- [x] 5.2 Inspect representative 72 mm output for a partial refund, full return, serial replacement chain, and split bundle; verify long serials wrap, monetary values remain intact, and current totals reconcile with the return details.

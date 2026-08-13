## 1. Schema, models, and authorization

- [x] 1.1 Add additive migrations for UOM-normalization headers, selected purchase/receipt line snapshots, immutable execution audit data, and uniqueness guards against overlapping normalized rows.
- [x] 1.2 Add nullable unique `received_note_detail_id` provenance to inventory transactions, model relationships, casts, indexes, and SQLite-compatible migration coverage.
- [x] 1.3 Add UOM-normalization entities/relationships for Purchase, PurchaseDetail, ReceivedNoteDetail, ProductUnitConversion, and Transaction.
- [x] 1.4 Add the dedicated `purchases.received.uom-normalize` permission and active-setting policy/authorization rules, including Super Admin behavior.

## 2. Receiving provenance and history resolution

- [x] 2.1 Update receiving approval so every newly created `BUY` transaction is persistently linked to the approved receiving detail that created it.
- [x] 2.2 Implement a legacy receiving-to-transaction resolver using product, setting, location, BUY type, purchase reference, receipt quantity, and approval chronology.
- [x] 2.3 Make the resolver return an explicit matched, missing, or ambiguous result and prohibit mutation unless a single source transaction is identified.
- [x] 2.4 Add tests for new provenance links and for unique, missing, and ambiguous legacy transaction matching.

## 3. Eligibility and preview domain services

- [x] 3.1 Implement product-level batch selection validation for one stock-managed non-serial product, one direct conversion to its existing base UOM, and explicitly selected purchase/approved receiving rows.
- [x] 3.2 Implement full-receipt validation that keeps incomplete selections previewable but prevents execution until all selected purchase lines are fully received.
- [x] 3.3 Implement stock-affecting history guards for dispatched/partially dispatched Sales, completed POS checkouts including bundle components, returns, transfers, adjustments, breakage, replacement dispatches, imports/initialization, and later relevant transaction rows.
- [x] 3.4 Ensure normal Sale drafts/approvals and POS draft, loaded, and cancelled transactions do not fail the eligibility guard.
- [x] 3.5 Implement a preview service that reports selected source/base quantities, monetary invariance, receipt locations, transaction-match state, blocker details, normalized unit cost, and projected current HPP.
- [x] 3.6 Add focused service tests for each eligible, incomplete, and blocked history scenario.

## 4. Atomic normalization and cost reconciliation

- [x] 4.1 Implement the execution service with transaction-level locks and a complete inside-transaction revalidation of conversion, selected rows, no-overlap state, receipt completion, transaction matches, and stock history.
- [x] 4.2 Update selected purchase-detail and approved receiving-detail quantities in place while preserving document totals, tax/discount values, supplier/payment data, identities, receipt locations, and serial links.
- [x] 4.3 Update the matched original BUY transaction rows in place, including quantity, tax/non-tax quantities, and global/location running snapshots in chronological order.
- [x] 4.4 Reconstruct and persist resulting product and per-location stock quantities and tax/non-tax buckets without adding a compensating inventory transaction.
- [x] 4.5 Recalculate normalized per-base-unit costs and current per-setting and synchronized product average/last purchase cost without rewriting sale HPP snapshots.
- [x] 4.6 Persist immutable normalization snapshots, conversion details, matched transaction IDs, reason, actor, execution time, and calculation outcome only after all updates succeed.
- [x] 4.7 Add rollback, idempotency, multi-purchase chronology, tax/non-tax bucket, precision, and concurrent-history regression tests.

## 5. Purchase-module UI and audit visibility

- [x] 5.1 Add authorized UOM-normalization entry points to existing Purchase action menus and purchase-detail actions, distinct from monetary correction.
- [x] 5.2 Build a Purchase-native Bootstrap/CoreUI batch form with conversion selection, explicit affected-line selection, incomplete-receipt state, and actionable eligibility feedback.
- [x] 5.3 Add preview and confirmation interactions using the project's standard inline/flash feedback and no blocking browser dialogs.
- [x] 5.4 Add read-only normalization audit cards/tables to every affected purchase detail, showing source/base quantities, factor, batch reference, reason, actor, and time.
- [x] 5.5 Add authorization and UI feature tests for visibility, denial, preview failures, successful execution feedback, and audit rendering.

## 6. Verification and operational readiness

- [x] 6.1 Add end-to-end feature coverage for a multi-purchase BOX-to-base-UOM normalization with no sales, ensuring original BUY transaction rows—not new correction rows—are updated.
- [x] 6.2 Add regression coverage proving commercial totals/payments and sale/POS drafts remain unchanged, while dispatched/completed outbound history blocks execution.
- [x] 6.3 Run focused Purchase, Product, Sale, POS, and report tests; then run the project SQLite test suite or the highest-confidence available equivalent.
- [x] 6.4 Document the operational workflow, eligibility boundaries, legacy transaction-match remediation, and rollback/incident procedure in the change quickstart or implementation notes.

## 1. Schema and lifecycle foundation

- [x] 1.1 Inspect current adjustment, product-stock, product aggregate, serial condition/status/tax, transaction, notification, and active-setting patterns; document the exact domain mappings needed for safe serial absence, movement, and conflict handling.
- [x] 1.2 Add MySQL/SQLite-compatible adjustment lifecycle metadata and `approval_result` storage with appropriate casts/indexes, and migrate redesigned normal `pending` documents to `draft` without adding legacy-format compatibility work.
- [x] 1.3 Add centralized normal Stock Opname lifecycle predicates/transitions for `draft`, `waiting_approval`, `rejected`, and `approved`, including revision metadata rules and active-setting ownership guards.
- [x] 1.4 Change count-draft create/update persistence to save `draft`, allow rejected revision, preserve prior rejection evidence as specified, and stop approval-needed notifications during ordinary save.

## 2. Reconciliation model and queries

- [x] 2.1 Define typed reconciliation result structures for document summary, product baseline/current/entered/projected values, drift, global effects, warnings, serial classifications, conflicts, and applied audit evidence.
- [x] 2.2 Implement bulk scoped queries for entered products, selected-location stocks, same-owner eligible-location totals, destination setting PKP, matching product serials, and destination serial omissions without queries inside row or serial loops.
- [x] 2.3 Implement non-serialized absolute-count reconciliation, including separate good/bad differences, condition reclassification, selected-location effect, all-location total, projected global total, and over-total warnings.
- [x] 2.4 Implement serialized reconciliation for already-present, cross-location movement, new-for-product, condition change, tax change, same-text-other-product warning, destination omission, drift, and unsafe conflict classifications.
- [x] 2.5 Make current destination `setting.is_pkp` authoritative in reconciliation and expose Bahasa Indonesia `Kena Pajak → Tidak Kena Pajak` and reverse classifications without trusting client or saved serial tax state.
- [x] 2.6 Expose separate counter-safe and permitted-reviewer projections so protected baseline, stock, serial-source, tax, and impact facts never enter unauthorized response or Livewire state.

## 3. Submission and decision lifecycle

- [x] 3.1 Add idempotent submit routing/action for an owned nonempty `draft`, record submitter/time, transition to `waiting_approval`, lock editing, and send one approval-needed notification.
- [x] 3.2 Update edit/update/delete guards and index actions so only allowed draft/rejected operations appear and submitted/approved documents cannot be mutated through direct requests.
- [x] 3.3 Add idempotent rejection routing/action restricted to owned `waiting_approval` documents and `adjustments.approval`, requiring a Bahasa Indonesia reason and recording rejector/time without inventory mutation.
- [x] 3.4 Replace redesigned approval blocking with a guarded approval entry point restricted to owned `waiting_approval` documents and `adjustments.approval`; prevent legacy approval code from receiving redesigned documents.

## 4. Atomic inventory approval

- [ ] 4.1 Implement locked reconciliation inside one database transaction, locking the adjustment, entered products, affected source/destination stock rows, and relevant serial rows before recomputing current classifications.
- [ ] 4.2 Apply absolute destination good/bad quantities only for entered non-serialized products, allocate entirely by destination PKP, leave omitted products and other locations unchanged, and update product aggregates by the verified net difference.
- [ ] 4.3 Move entered existing serials from source to destination with exact good/bad and tax/non-tax source decrements, proposed condition, destination PKP classification, and no unintended global quantity change.
- [ ] 4.4 Create still-unknown entered serials only during approval with destination location, proposed condition, and destination PKP classification; reject duplicate or unsafe concurrent identity conflicts.
- [ ] 4.5 Apply the chosen domain-consistent disposition for omitted available destination serials, block serials with unsafe active links, and record every omission outcome rather than silently deleting evidence.
- [ ] 4.6 Write inventory transactions and stock notifications for actual affected locations/products, persist the immutable locked `approval_result`, record approver/time, resolve document notifications, and guarantee rollback on any failure.

## 5. Bahasa Indonesia review interface

- [ ] 5.1 Rebuild the Stock Opname detail header and status/actions in Bahasa Indonesia, remove the debug permission panel, and show submit/approve/reject/edit controls only for valid status-permission combinations.
- [ ] 5.2 Render the counter-safe view with only document metadata and entered product, good/bad count, serial text, and entered condition information.
- [ ] 5.3 Render the permitted reviewer summary with product-difference count, potential global increases, moved/new serial totals, tax reclassification totals, drift totals, and conflicts.
- [ ] 5.4 Render the permitted product comparison table separating `Saat mulai dihitung`, `Saat ini`, `Hasil hitung`, and `Setelah disetujui`, including selected-location and all-location effects.
- [ ] 5.5 Add expandable serial-impact details with Bahasa Indonesia classifications for retained, moved, new, condition-changed, tax-changed, omitted, and conflicting serials, including source and destination locations where permitted.
- [ ] 5.6 Render approved documents from immutable `approval_result`, add Bahasa Indonesia rejection/revision history, and normalize index statuses, confirmations, validations, notifications, and audit messages.

## 6. Focused verification and human handoff

- [ ] 6.1 Add focused lifecycle tests for draft save without notification, explicit idempotent submission, edit locks, required rejection reason, rejected revision, wrong-state decisions, and actor/timestamp metadata.
- [ ] 6.2 Add focused permission tests proving counter HTML/public state contains only entered facts while permitted reviewers receive reconciliation data and review permission alone does not grant approval.
- [ ] 6.3 Add focused non-serial reconciliation and approval tests for PKP/non-PKP allocation, good/bad reclassification, all-location warning, selected-location absolute update, omitted-product preservation, product aggregate consistency, and drift.
- [ ] 6.4 Add focused serial tests for same-location retention, cross-location movement, new creation, condition change, taxable-to-non-tax and reverse movement, same text on another product, omitted destination serials, conflicts, and locked revalidation.
- [ ] 6.5 Add focused transaction tests proving source/destination buckets, serial records, inventory transactions, immutable applied results, notification resolution, idempotency, and complete rollback remain consistent under failures.
- [ ] 6.6 Add focused active-setting and consignment tests for show/edit/update/delete/submit/approve/reject plus product/location resolution, without running or planning the full suite.
- [ ] 6.7 Prepare a human-executed browser checklist covering Bahasa Indonesia counter/reviewer/approved views, hidden system facts, submission, summaries, warnings, serial expansion, confirmation, approval, rejection, and revision; report browser execution as pending unless a human records completion.

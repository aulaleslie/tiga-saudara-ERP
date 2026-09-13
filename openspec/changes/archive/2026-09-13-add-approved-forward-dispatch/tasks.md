## 1. Persist Dispatch Observations and Approval Provenance

- [x] 1.1 Add additive migrations for movement-line count confirmation, applied quantities by eligible stock bucket, before/after stock snapshots, and inventory transaction references with the foundation's decimal precision.
- [x] 1.2 Add a portable database-backed active serial-custody claim and custody timestamps/context that enforce at most one unresolved claim per live serial.
- [x] 1.3 Update movement entities, casts, relationships, and invariants for confirmed-zero observations, immutable applied allocation, transaction provenance, and active custody.

## 2. Build Authoritative Dispatch Preparation

- [x] 2.1 Add a forward-dispatch preparation service that creates or resumes the one open attempt for the exact approved workflow version `2` transfer revision and seeds every approved product as unconfirmed.
- [x] 2.2 Adapt the established transfer barcode, conversion, serial, and tokenized product-search behavior to movement lines while reloading product, location, condition, conversion, and serial authority on every mutation.
- [x] 2.3 Support unexpected product observations and alternate eligible serials without classifying them in blind preparation, and prevent duplicate serial selection.
- [x] 2.4 Implement explicit confirmation and unconfirmation semantics so positive edits confirm their line, zero requires a deliberate action, and every approved product must be confirmed before submission.
- [x] 2.5 Revalidate transfer revision and live serialized eligibility at submission, freeze the physical observation without applying stock, and allow mismatched or all-zero counts to become `PENDING`.

## 3. Enforce Permission-Aware Projections and Entry Points

- [x] 3.1 Add separate blind and privileged preparation projections so users without `stockTransfers.view-system-stock` receive product identity, condition, their entries, serials, and confirmation only.
- [x] 3.2 Add separate blind and privileged approval projections, returning only neutral match/failure guidance to blind approvers and exact comparison/allocation details to privileged approvers.
- [x] 3.3 Add origin-side authorization wrappers for prepare, edit, submit, cancel, correct, approve, and reject using the existing dispatch create/approval permissions without implying stock visibility.
- [x] 3.4 Add version-aware forward-dispatch routes and Livewire surfaces behind a default-disabled activation boundary, reject crafted disabled/version-mismatched requests, and leave all other movement types dormant.

## 4. Compare and Approve Forward Dispatch Atomically

- [x] 4.1 Implement a server-side canonical comparator for the exact approved revision using product, condition, decimal base quantity, and normalized serialized identity sets independent of ordering.
- [x] 4.2 Implement locked authoritative allocation for `GOOD` and `BREAKAGE` stock using the established non-tax-first rule, treating bucket drift as provenance rather than a request/count mismatch.
- [x] 4.3 Implement a dedicated idempotent forward-dispatch approval executor that locks all authoritative rows and atomically validates, deducts origin stock, records inventory transactions and immutable line snapshots, approves/history-stamps the movement, and projects the transfer header to `DISPATCHED`.
- [x] 4.4 Activate exclusive `IN_TRANSIT` custody for approved serials without changing their live origin location, and centralize active-custody exclusion across sale, dispatch, return, and transfer availability paths.
- [x] 4.5 Preserve a pending movement and roll back every inventory, custody, transaction, history, and header effect when comparison, fulfillment, concurrency, or any later approval step fails.
- [x] 4.6 Keep explicit rejection and superseding correction behavior immutable, including independent preparer/approver audit identities and allowed same-user approval when permissions permit.

## 5. Focused Verification

- [x] 5.1 Add focused migration/model tests for confirmation, decimal allocation snapshots, transaction references, custody fields, and active-claim uniqueness on supported test databases.
- [x] 5.2 Add focused preparation and scanner tests for base/conversion scans, serial scans, search, unexpected products, alternate serials, duplicate prevention, explicit zero, all-zero submission, and immutable submitted attempts.
- [x] 5.3 Add focused authorization and payload-leakage tests for origin ownership, action permissions, blind/privileged preparation and approval projections, exception neutralization, and crafted client values.
- [x] 5.4 Add focused comparison and approval tests for exact/mismatched quantities and serial sets, allocation drift, insufficient stock, atomic rollback, idempotent replay, transfer-header projection, and audit provenance.
- [x] 5.5 Add focused serial-custody concurrency and availability regressions plus version `1` compatibility and default-disabled version `2` activation tests.

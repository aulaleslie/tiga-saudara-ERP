# Tasks

## 1. Row attachment input

- [x] 1.1 Add a multiple-file attachment control beside each global payment allocation amount, keep the existing purchase context columns, and verify the rendered form gives each uploader a stable purchase ID.
- [x] 1.2 Reuse the individual payment staging and deletion endpoints; keep file names keyed by purchase across DataTables pages, prevent submit during uploads or when a zero-amount row has files, and verify a focused form test covers off-page rows and row-specific hidden inputs.

## 2. Server-side payment storage

- [x] 2.1 Change the global controller request contract to accept `attachments[purchase_id][]` and reject malformed input; verify focused request tests cover valid, zero-file, and malformed payloads.
- [x] 2.2 Validate all staged files with the individual payment rules, reject duplicate file references across rows and files on zero allocations, and verify focused service tests cover valid and tampered row associations.
- [x] 2.3 Store each row's files only on its generated `PurchasePayment`, preserve original-name metadata, and clean created media after any failure; verify focused service tests cover distinct multi-file rows, empty rows, and rollback after a later row fails.

## 3. Focused verification and human review

- [x] 3.1 Update the existing global attachment tests to the per-row contract and run focused Purchase module payment tests; verify the selected tests pass without running the full suite.
- [x] 3.2 Prepare a concise manual browser checklist for a human to verify per-row uploads, pagination, zero-amount blocking, file removal, and payment attachment links; record the checklist as the handoff artifact without treating an automated browser run as a task.

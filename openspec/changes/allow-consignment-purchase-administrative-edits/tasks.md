# Tasks

## 1. Administrative field boundaries

- [x] 1.1 Extend the consignment Purchase header allowlist only for reporting_date, due_date, note, supplier_purchase_number, and tax_ref_no; verify focused model tests accept these fields and reject commercial, source, and mixed protected changes without partial persistence.
- [x] 1.2 Review note and invoice-reference inline editors for normal/global authorization and standard validation parity; verify focused Livewire tests cover successful saves, clearing, uniqueness/length errors, denied access, and unchanged source snapshots on existing converted Purchases.

## 2. Standard date maintenance

- [x] 2.1 Remove consignment-only date exclusions from Purchase policies and relevant source/service guards while retaining standard authorization and lifecycle eligibility; verify focused date tests cover reporting set/replace/clear, explicit due-date replacement, original-date fallback, unchanged payment terms, and standard audit records.
- [x] 2.2 Verify normal and global date entry paths retain independent permissions, tenant/global/archive checks, Super Admin behavior, reason validation, and atomic rollback for combined requests using focused authorization and failure tests.
- [x] 2.3 Verify shared Purchase views and an existing effective-date/due-date consumer display persisted consignment date adjustments with the same semantics as ordinary Purchases, using focused view/report assertions; do not introduce original transaction-date rewriting or automatic due-date recalculation.

## 3. Attachment and informational UI parity

- [x] 3.1 Remove consignment-source attachment deletion restrictions in backend and UI, preserving ordinary upload validation, media ownership, collection checks, and access rules; verify focused attachment tests cover upload, deletion of original and later-added Purchase attachments, denied/foreign deletion, and preservation of independent source attachments and conversion evidence.
- [x] 3.2 Update shared normal/global informational copy and applicable administrative controls to explain editable metadata and locked commercial values; verify focused rendering tests match ordinary action availability and that full/monetary edit actions remain unavailable for consignment Purchases.

## 4. Focused integration verification

- [x] 4.1 Run an explicitly selected set of affected Purchase/Consignment and Livewire tests covering existing/new converted Purchases, administrative actions, unchanged ordinary-Purchase behavior, commercial/lifecycle rejection, payment behavior, and source/inventory preservation; record exact paths/filters and results. Do not run or require the full test suite.
- [x] 4.2 Run `openspec validate allow-consignment-purchase-administrative-edits --strict` and `git diff --check`; verify both pass and only intended change files are included.

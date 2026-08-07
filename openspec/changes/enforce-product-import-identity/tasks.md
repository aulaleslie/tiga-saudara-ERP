## 1. Canonical identity foundation

- [x] 1.1 Inventory every production import entry point that resolves or creates `Product` records and document each source's marker policy and creation defaults.
- [x] 1.2 Add a shared Product-module canonicalizer that normalizes supported Unicode whitespace, documented source markers, display names, and case-folded identity keys.
- [x] 1.3 Add unit tests covering case, repeated/Unicode whitespace, leading `*`, trailing standalone `TP`, and preservation of non-marker punctuation/text.
- [x] 1.4 Add a nullable canonical identity key and supporting schema/index changes compatible with MySQL and SQLite; ensure all new import-created products persist it.
- [x] 1.5 Implement the shared resolver's explicit existing-only and resolve-or-create operations, including scoped handling of canonical-key unique conflicts.
- [x] 1.6 Add concurrency-focused tests proving simultaneous creation attempts for one canonical identity result in one product.

## 2. Import path integration

- [x] 2.1 Refactor `PurchaseImportService` product resolution to use the shared resolver while preserving CSV code, owner-routing, units, and purchase-price behavior.
- [x] 2.2 Refactor `SalesImportService` product resolution to use the shared resolver while preserving marker-based ownership, units, dispatch, and sales-price behavior.
- [x] 2.3 Refactor generic Product CSV import processing to use the shared resolver/canonical key and retain its current duplicate reporting semantics.
- [x] 2.4 Refactor HPP and any stock/snapshot import path that can create or resolve a product to use the correct shared resolver operation.
- [x] 2.5 Refactor dual-company tier-price import matching to use shared canonical existing-product resolution and retain its no-create behavior.
- [x] 2.6 Refactor Accurate sales-price and stock snapshot matching to use shared canonical existing-product resolution and retain actionable unmatched/conflicted outcomes.
- [x] 2.7 Add focused integration tests for each path showing formatting and owner-marker variants reuse one product and that distinct identities remain distinct.

## 3. Existing duplicate remediation

- [x] 3.1 Implement a read-only catalog identity preflight report/command listing each collision group's products, names, codes, canonical key, and supported reference counts.
- [x] 3.2 Define and implement auditable survivor-selection and redundant-product retirement storage/state without destructive deletion.
- [ ] 3.3 Implement operator-confirmed, per-group transactional reference migration for the supported product relations; reject unsupported or unsafe groups before mutation.
- [ ] 3.4 Add tests for no-op preflight, collision reporting, successful survivor reconciliation, rollback on a failed reference migration, and post-reconciliation canonical uniqueness.
- [ ] 3.5 Run the preflight against the fresh import database, reconcile the reported duplicate groups with explicit operator selections, then complete canonical-key backfill.

## 4. Verification and operational handoff

- [x] 4.1 Add regression coverage for the reported ambiguous price-upload names and verify they resolve to exactly one product after reconciliation.
- [x] 4.2 Run focused Product, Purchase, Sales, snapshot, and dual-company tier-price import tests under SQLite.
- [ ] 4.3 Run `composer test:fresh-sqlite` or an equivalent full migration/test validation and investigate any import-regression failures.
- [x] 4.4 Document the initialization order: preflight, explicit reconciliation, canonical backfill, and price workbook re-upload, including rollback/audit expectations.

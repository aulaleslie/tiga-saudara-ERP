# Design

## Context

See proposal.md for motivation. `Brand`, `Category`, and `Product` extend `BaseModel`, which uppercases and trims text but provides no setting scope. Explicit filters remain in category/brand dropdowns, quick-add modals, category parent selectors, and controller validation. `ProcessProductImportChunk` already matches names globally but uses arbitrary first matches and writes setting ownership.

The inspected local MySQL snapshot has 43 categories, 29 brands, and 7,139 products, with no orphan category/brand references and no deleted brands. The proposed lowest-ID mapping yields 32 categories and 23 brands, changing 105 category references and 14 brand references. These are snapshot observations, not hard-coded execution assertions. `ALFA INK` has child `TINTA ALFA INK`; other observed categories are roots.

`products.category_id` and `products.brand_id` use SET NULL on deletion. `categories.parent_id` cascades on deletion. Both taxonomy setting ownership foreign keys cascade on business deletion. Brands use soft deletion; categories use hard deletion. Existing product reconciliation tests and import identity specifications provide patterns, but their product-specific behavior remains unchanged.

## Goals / Non-Goals

**Goals:** Deterministic identity, portable uniqueness, reference-preserving reconciliation, and complete removal of business ownership from taxonomy writers/readers.

**Non-Goals:** Product deduplication, filling missing taxonomy values, typo/alias cleanup, expense-category changes, rewriting transaction/audit snapshots, or changing stock and price ownership.

## Decisions

### One normalization and resolution service

Introduce focused services under `Modules/Product/Services` for normalization and taxonomy resolution, used by controllers, quick-add, imports, and reconciliation. Replace Unicode separator whitespace with ordinary spaces, collapse runs, trim, and case-fold identity text. Keep the established uppercase display convention. Do not remove punctuation or product-import owner markers from taxonomy names. Empty normalized names fail validation.

Brand key is normalized name. Category key is normalized name plus canonical parent ID, with roots mapped to a non-null root namespace. Parent groups resolve topologically before children. Use stored normalized identity fields and a database unique key for brands and for category namespace/name. A non-null namespace avoids MySQL/SQLite nullable composite-key loopholes. Compare normalized fields with semantics consistent across engines; do not allow database accent-insensitive collation to widen the agreed identity equivalence. A parent move checks ancestors and sibling identity before atomically updating namespace/parent.

Application-only validation was rejected because concurrent requests can pass it simultaneously. Duplicate-key failures become validation errors or an import reload of the winning identity. A name-only category import reuses a unique active match across paths, reports ambiguity when several exist, and creates a root only when no match exists. This preserves existing unambiguous child matches without arbitrary selection.

### Separate schema preparation, reconciliation, and final constraints

Do not hide data deletion inside a migration. Add nullable identity fields and an audit table first, retaining legacy ownership until the explicit cleanup completes. Build a read-only preview command and an explicit execution mode using a plan fingerprint, operator identity, and confirmation flag. Fingerprint candidate records, hierarchy, metadata, and affected reference state; execution locks and recomputes the plan before mutation. Execute the complete valid taxonomy plan as one transaction, with deterministic locking and a write pause for other taxonomy writers.

Retain lowest active IDs, survivor metadata, and survivor category codes. Include conflicting metadata in preview and preserve original rows in the durable audit; do not silently combine descriptions. Remap product foreign keys and category parents before hard-removing redundant rows, including duplicate brands. Brand merge removal bypasses ordinary soft-delete behavior only inside audited reconciliation; ordinary brand deletion remains soft deletion. Historical audit payloads remain immutable. Unsupported incoming database foreign keys or invalid/cyclic/orphan hierarchy block cleanup rather than being ignored.

Conservative default for presently absent deleted-brand cases: reserve deleted brand names, report them separately, and block conflicting creation/import rather than automatically restoring or merging them. The final uniqueness rule includes deleted brands. If existing deleted records collide, execution reports the conflict and requires a separate explicit disposition; it never silently revives them.

After cleanup, finalization preflights populated identity fields and zero conflicts, adds uniqueness constraints, and drops taxonomy setting foreign keys/indexes/columns. MySQL DDL can implicitly commit, so this is separate from transactional DML. Constraint finalization can be retried after a failure without repeating reconciliation.

### Remove every business dependency

Update entities, controllers, root/parent lists in `ProductController`, datatables as needed, search dropdown initial selection and search paths, both quick-add paths, import writers, factories, seeders, and test helpers. Keep authorization gates and `created_by`. Check references to taxonomy setting fields by targeted source search. Expense categories and product setting fields are outside scope.

### Focused verification

Use focused PHPUnit/Livewire tests in the existing module/test structure rather than a full-suite requirement. Cover normalization, parent-aware identity, distinct punctuation/spelling, active/deleted conflicts, edit/move validation, business switching, permissions, quick-add and import reuse, import ambiguity, and duplicate-key conflict handling.

Exercise reconciliation using realistic fixtures with duplicate parents/children, SET NULL and CASCADE relationships, null relations, stale plans, forced rollback, audit data, and idempotence. Assert exact mappings and unchanged product IDs and unrelated stock/price/document data. Run one isolated MySQL database check for actual foreign-key behavior, unique-key collision/concurrent inserts, root identity uniqueness, and phased DDL; SQLite tests alone cannot establish those behaviors. No automated tests connect to the developer's working database.

## Risks / Trade-offs

- Shared edits affect every business → retain existing permission gates and test cross-business visibility.
- Deletion cascades or clears links → remap before deletion and verify postconditions inside the transaction.
- Concurrent jobs recreate duplicates during deployment → pause writes/queues until reconciliation and final constraints finish.
- Parent-path preservation makes name-only imports ambiguous → actionable row error instead of arbitrary first match.
- Collation or nullable unique keys weaken identity → explicit comparison semantics, non-null namespaces, and isolated MySQL checks.
- Cleanup cannot reconstruct old references from surviving records → durable before-state audit plus verified database backup; rollback uses backup restoration, not fabricated ownership.

## Migration Plan

1. Back up the target database; pause taxonomy mutations and affected import workers. Deploy the compatible preparation tooling and schema.
2. Generate a fresh preview; compare mapping/counts with the inspected snapshot where applicable, and review metadata and hierarchy conflicts.
3. Execute the explicitly confirmed fingerprinted plan transactionally; verify mappings, referential integrity, and unchanged unrelated data. A failed DML transaction leaves original rows/references intact.
4. Finalize identity constraints and remove setting ownership. Deploy/activate the final readers and writers together; ensure no old worker still writes removed columns.
5. Run focused verification and a cross-business smoke check, then resume writes and workers. If finalization fails, remain paused and retry after correction. A rollback after successful cleanup/schema removal restores the verified backup and matching application version during the maintenance window; ordinary migration down cannot reverse deduplication.

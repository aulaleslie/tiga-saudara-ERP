## Why

Purchase creation can currently derive an already-used internal reference because purchase and sale allocators infer the next sequence from the latest row ID and document date rather than from an authoritative sequence. The same unsafe pattern exists across normal creation, imports, model fallbacks, cross-business moves, and POS-generated Sales, so the system needs one durable, concurrency-safe numbering capability before another production collision occurs.

## What Changes

- Introduce a shared database-backed sequence allocator for internal Purchase and Sale references, scoped by document type, business, prefix, year, and month.
- Bootstrap sequence state from all existing active and archived Purchase and Sale references, with a dry-run reconciliation report for malformed references, unexpected namespaces, embedded-period/date drift, and unsafe counter values.
- Route every production Purchase and Sale writer through the shared allocator, including normal forms, imports, duplication/model fallback paths, cross-business draft moves, ordinary POS Sales, non-stock owner-resolved POS Sales, and every owner-specific Sale created by POS split posting.
- Allocate references and persist their documents atomically while retaining the existing `(setting_id, reference)` database uniqueness constraints as the final integrity guard.
- Define stable behavior for date edits, business moves, prefix changes, failed transactions, conflict reconciliation, and bounded retry.
- Correct failed-submission idempotency lifecycle so a rolled-back Purchase or Sale attempt can be retried without permitting duplicate successful submissions.
- Add a disposable MySQL 8.0.44 Docker verification environment matching production isolation, SQL mode, character set, collation, and UTC+08:00 timezone behavior.
- Add focused Purchase, Sale, POS split, bootstrap, rollback, and real multi-process concurrency tests; full-suite execution is not required for this change.
- Keep returns, transfers, expenses, quotations, dispatch references, and other document families outside this rollout.

## Capabilities

### New Capabilities

- `purchase-sales-document-sequencing`: Authoritative internal reference allocation, reconciliation, lifecycle rules, Purchase/Sale integration coverage including POS split Sales, failure recovery, and scoped MySQL concurrency verification.

### Modified Capabilities

None.

## Impact

- Affected schema: a new document-sequence table and its uniqueness/index constraints; existing Purchase and Sale uniqueness constraints remain.
- Affected application paths: `DocumentReferenceService`, Purchase and Sale models/services/forms/importers, cross-business draft movement, POS checkout posting adapters, and idempotency handling.
- Affected operational workflow: a pre-cutover dry-run/bootstrap command and staged Purchase-then-Sale activation using the same shared allocator.
- Affected test infrastructure: a test-only Docker Compose definition and PHPUnit configuration for scoped MySQL 8.0.44 tests alongside existing fast SQLite tests.
- No external API contract or historical Purchase/Sale reference is rewritten.

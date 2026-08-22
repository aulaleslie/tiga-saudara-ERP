## 1. Inventory and Safety Baseline

- [ ] 1.1 Inventory every production Purchase and Sale reference writer, including services, model hooks, imports, duplication flows, cross-business moves, inline POS posting, non-stock owner resolution, and split POS posting, and record the intended shared-allocator integration for each path.
- [ ] 1.2 Add focused regression fixtures that reproduce out-of-order row IDs, archived references, embedded-period/date drift, and the observed `setting_id` plus existing-reference collision before replacing legacy allocation logic.
- [ ] 1.3 Confirm the existing `(setting_id, reference)` unique indexes for both `purchases` and `sales` with a focused schema test and preserve them throughout the change.

## 2. Sequence Schema and Domain Types

- [ ] 2.1 Add the additive InnoDB-compatible `document_sequences` migration with document type, setting, complete prefix, year, month, last number, timestamps, and a unique namespace constraint.
- [ ] 2.2 Implement typed Purchase/Sale document-type identifiers and immutable namespace/allocation value objects with canonical comparison and serialization behavior.
- [ ] 2.3 Implement one reference formatter/parser that supports existing Purchase and Sale formats, numeric suffixes beyond five digits, and explicit rejection of malformed or ambiguous references.
- [ ] 2.4 Add focused unit tests for namespace separation, prefix changes, formatting, parsing, canonical ordering, malformed references, and long numeric suffixes.

## 3. Authoritative Allocator

- [ ] 3.1 Implement the shared allocator so it requires an encompassing database transaction, creates missing namespace rows race-safely, locks counters with `FOR UPDATE`, and increments counters atomically.
- [ ] 3.2 Implement canonical multi-namespace locking for callers such as POS split posting, sorting by document type, setting, prefix, year, and month before acquiring locks.
- [ ] 3.3 Implement stale-counter reconciliation and a single bounded whole-operation retry for unique-reference conflicts while preserving the database constraint as the authority.
- [ ] 3.4 Emit structured allocation, rollback, reconciliation, retry, and terminal-failure diagnostics without sensitive Purchase, Sale, customer, supplier, or payment payloads.
- [ ] 3.5 Add focused allocator tests for independent namespaces, rollback-safe increments, missing-row races, stale-counter repair, bounded retry, and terminal conflicts.

## 4. Legacy Reconciliation and Bootstrap

- [ ] 4.1 Implement a reconciliation service that scans active and archived Purchase and Sale references by embedded namespace and calculates monotonic historical maxima without grouping by editable document dates.
- [ ] 4.2 Add an Artisan command with document-family selection and `--dry-run` support that reports malformed references, unexpected prefixes, embedded-period/date drift, and counters below history.
- [ ] 4.3 Implement repeatable bootstrap/upsert behavior that only advances counters and never edits, renumbers, unarchives, or otherwise mutates historical documents.
- [ ] 4.4 Add focused bootstrap tests for archived rows, out-of-order IDs, date drift, malformed references, unexpected prefixes, existing higher counters, and repeated execution.
- [ ] 4.5 Document the anonymized-production-backup dry run, blocker review, bootstrap, smoke checks, and monotonic recovery procedure for a stale counter.

## 5. Purchase Integration and First Cutover

- [ ] 5.1 Refactor normal Purchase creation to allocate and insert the Purchase atomically through the shared allocator.
- [ ] 5.2 Refactor Purchase import, duplication, and every remaining production Purchase creation path to the same allocator while keeping supplier external numbers separate.
- [ ] 5.3 Refactor drafted Purchase cross-business moves to allocate from the target namespace atomically while preserving the existing reference on date-only edits and blocking non-draft moves.
- [ ] 5.4 Remove the Purchase model's independent `latest('id')` numbering algorithm and make unsupported unallocated production inserts delegate safely or fail explicitly.
- [ ] 5.5 Add a guarded Purchase-family activation/cutover mechanism that fails closed without bootstrapped sequence state and cannot run old and new Purchase allocators concurrently.
- [ ] 5.6 Add focused Purchase tests covering normal creation, imports, duplication/fallback policy, archived references, date edits, business moves, rollback, prefix changes, and staged activation.

## 6. Sale Integration and Second Cutover

- [ ] 6.1 Refactor standard Sale creation to allocate and insert the Sale atomically through the shared allocator.
- [ ] 6.2 Refactor Sale import, duplication, and every remaining non-POS production Sale creation path to the same allocator while keeping imported customer invoice numbers separate.
- [ ] 6.3 Refactor drafted Sale cross-business moves to allocate from the target namespace atomically while preserving the existing reference on date-only edits and blocking non-draft moves.
- [ ] 6.4 Remove the Sale model's independent `latest('id')` numbering algorithm and make unsupported unallocated production inserts delegate safely or fail explicitly.
- [ ] 6.5 Add a guarded Sale-family activation/cutover mechanism that fails closed without bootstrapped sequence state and cannot run old and new Sale allocators concurrently.
- [ ] 6.6 Add focused Sale tests covering normal creation, imports, duplication/fallback policy, archived references, date edits, business moves, rollback, prefix changes, and staged activation.

## 7. POS Sale Integration

- [ ] 7.1 Refactor inline POS Sale posting to supply an explicitly allocated reference for the Sale's final effective owner setting, including the non-stock owner-resolution path.
- [ ] 7.2 Refactor split POS posting to pre-resolve all distinct Sale namespaces, acquire them in canonical order, and allocate every group Sale inside the existing checkout transaction.
- [ ] 7.3 Preserve checkout atomicity so a failure in any later group rolls back all generated Sales, mappings, inventory effects, payments, and sequence increments.
- [ ] 7.4 Add focused POS tests for single-owner checkout, non-terminal non-stock ownership, two-business split prefixes, multiple groups sharing an owner, reverse-owner lock order, later-group rollback, and checkout retry idempotency.

## 8. Idempotency Failure Lifecycle

- [ ] 8.1 Extend `IdempotencyService` with explicit claim-complete-fail/release lifecycle behavior that preserves completed duplicate protection and permits retry after rollback.
- [ ] 8.2 Integrate lifecycle completion only after successful Purchase and Sale commits and failure cleanup across validation-safe exception, allocation-conflict, and persistence rollback paths.
- [ ] 8.3 Verify POS checkout's existing idempotency lifecycle remains compatible with sequence rollback and does not create duplicate Sales on retry.
- [ ] 8.4 Add focused tests for failed Purchase retry, failed Sale retry, completed-token duplicate prevention, response-after-commit safety, TTL behavior, and rolled-back POS retry.

## 9. MySQL 8.0.44 Docker Test Harness

- [ ] 9.1 Add a test-only Docker Compose service pinned to `mysql:8.0.44` with a health check, disposable storage by default, dedicated `_test` database credentials, InnoDB, `REPEATABLE-READ`, the production SQL mode, `utf8mb4`, `utf8mb4_0900_ai_ci`, and UTC+08:00 behavior.
- [ ] 9.2 Add a MySQL-specific PHPUnit configuration and environment example that do not inherit the current forced SQLite connection and contain no production secrets.
- [ ] 9.3 Add a guarded scoped-test script or Composer command that refuses non-`_test` databases, starts/waits for MySQL, asserts version and settings, runs `migrate:fresh`, forwards a focused test path/filter, and cleans up after success or failure.
- [ ] 9.4 Implement independent PHP concurrency workers using the same MySQL schema, a deterministic start barrier, independently committed transactions, bounded timeouts, and captured worker diagnostics without `DatabaseTransactions` or PHPUnit database isolation.
- [ ] 9.5 Add MySQL concurrency cases for same-namespace Purchase creation, mixed standard/POS Sale creation, missing counter-row races, rollback after allocation, and reverse-order multi-owner POS checkouts.

## 10. Scoped Verification and Rollout Readiness

- [ ] 10.1 Run the dedicated formatter/parser, allocator, bootstrap, Purchase, Sale, POS, and idempotency tests with focused `php artisan test` paths or filters under SQLite where compatible.
- [ ] 10.2 Run only the dedicated allocator, Purchase, Sale, POS, bootstrap, idempotency, and multi-process concurrency tests against Dockerized MySQL 8.0.44 and retain failure diagnostics.
- [ ] 10.3 Run focused Laravel formatting/static checks on changed PHP files and verify both activation gates fail closed when bootstrap state is absent.
- [ ] 10.4 Produce Purchase-first and Sale/POS-second cutover checklists covering maintenance entry, dry run, blocker resolution, bootstrap, activation, smoke test, monitoring, and application rollback without dropping populated sequence data.
- [ ] 10.5 Verify no production Purchase or Sale writer retains `latest('id')` or date-scoped reference allocation and document all deliberately out-of-scope document families for follow-up work.

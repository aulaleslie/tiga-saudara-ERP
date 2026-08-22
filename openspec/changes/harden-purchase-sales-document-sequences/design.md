## Context

Purchase and Sale reference generation is duplicated across `DocumentReferenceService`, model creation hooks, import services, cross-business move logic, and POS posting. These implementations query the most recently inserted document for a selected document-date month and increment the suffix found there. The production incident demonstrated that row-ID order, document date, and reference sequence can diverge: `PD-BL-2026-08-00181` already existed for setting 6 while the allocator independently generated the same reference. The database uniqueness constraint prevented corruption but aborted the submission, and the claimed idempotency token then prevented an immediate retry.

The change crosses Laravel services, Eloquent models, importers, Livewire submission flows, and the POS split-posting transaction. Production is MySQL Community Server 8.0.44 using InnoDB semantics, `REPEATABLE-READ`, strict SQL mode, `utf8mb4`, `utf8mb4_0900_ai_ci`, and UTC+08:00 system time. Local fast tests currently force SQLite, so locking behavior needs a separate disposable MySQL test environment.

## Goals / Non-Goals

**Goals:**

- Establish one authoritative, database-backed sequence mechanism for internal Purchase and Sale references.
- Make counter advancement and document persistence atomic under concurrent normal, import, cross-business, and POS traffic.
- Cover every owner-specific Sale produced by POS split posting without introducing inconsistent lock ordering.
- Reconcile legacy references safely without rewriting history.
- Preserve safe retry behavior after rolled-back submissions.
- Verify real InnoDB locking and rollback using focused MySQL 8.0.44 tests in Docker.
- Support a staged Purchase-first, Sale-second production cutover using the same final architecture.

**Non-Goals:**

- Migrating return, transfer, expense, quotation, dispatch, consignment, or other document reference generators.
- Renumbering or normalizing historical Purchase or Sale references.
- Treating supplier/customer external document numbers as internal sequence inputs.
- Requiring the repository's full automated suite for this focused change.
- Guaranteeing gapless numbering across committed deletions or intentional future administrative actions; the guarantee is rollback-safe allocation for failed transactions.

## Decisions

### 1. Persist one counter row per complete reference namespace

Create `document_sequences` with an internal ID plus `document_type`, `setting_id`, `prefix`, `period_year`, `period_month`, `last_number`, and timestamps. Add a unique key across `(document_type, setting_id, prefix, period_year, period_month)` and use InnoDB. `document_type` initially permits only Purchase and Sale identifiers through application-level typed constants or an enum; the schema remains extensible to later document families.

The prefix stored in the namespace is the complete effective prefix before the year/month/suffix portion. This makes prefix changes explicit new namespaces and prevents a changed setting configuration from corrupting historical counters.

Alternative considered: calculate `MAX()` from document reference strings on every create. This is a smaller patch but retains parsing cost and legacy-data ambiguity in the hot path. Alternative considered: one counter column on `settings`; this cannot represent independent document types, prefixes, or monthly periods.

### 2. Use embedded reference identity for bootstrap and persisted counters thereafter

A reconciliation service parses existing active and archived references using the configured/recognized Purchase and Sale formats. It groups valid references by embedded prefix, year, and month and records the highest numeric suffix. Document `date` is reported when it disagrees with the embedded period but does not change the reference namespace. Malformed or ambiguous references are reported and never rewritten.

Bootstrap upserts counters using monotonic `max(existing_counter, historical_max)`. It is repeatable and supports `--dry-run`. Once a document family is cut over, allocation reads only authoritative counter rows except during explicit conflict reconciliation.

Alternative considered: group legacy records by document date. This recreates the production mismatch because database uniqueness is based on the full reference, not the date column.

### 3. Make allocation an operation inside the caller's document transaction

Introduce a shared allocator service with typed namespace/value objects. Its API requires an active transaction or accepts a transaction callback that covers both allocation and persistence. It locks the counter row using `SELECT ... FOR UPDATE`, increments `last_number`, formats the reference through one formatter, and returns the allocation for immediate persistence.

Creating a missing counter row must also be race-safe. The service attempts an insert protected by the namespace unique key, then locks and reloads the winning row. Initial creation reconciles against historical data during bootstrap/cutover rather than assuming zero in a live legacy namespace.

The existing `(setting_id, reference)` unique indexes remain. On an unexpected duplicate, the operation rolls back, reconciles the counter to at least the highest valid existing suffix, and retries the complete operation once. A second conflict is a terminal, observable error.

Alternative considered: reserve numbers in an independent committed transaction. That simplifies some callers but creates avoidable gaps on business-transaction failure and violates the requested rollback behavior.

### 4. Centralize all Purchase and Sale production writers

Normal Purchase/Sale creation services, import services, duplication paths, and cross-business draft moves will use the allocator. Model creation hooks will no longer contain an independent `latest('id')` algorithm. The implementation will inventory production call sites: an explicitly supplied internal reference must either be a trusted allocator result carried in the same transaction or a documented legacy/import exception rejected from ordinary runtime paths.

External supplier purchase numbers and imported customer invoice numbers retain their current fields and do not participate in internal numbering.

Alternative considered: retain the model hook as the universal allocator. Model events cannot naturally coordinate multi-document namespace locking and make transaction ownership too implicit, especially for POS split checkout.

### 5. Preserve document identity on date edits and reallocate only draft business moves

Changing a document's date alone does not regenerate its internal reference. An authorized move of a drafted Purchase or Sale locks the target namespace and assigns a new target-business reference in the same update transaction. The source reference remains historical and reserved because counters never decrement. Existing restrictions continue to block non-draft business moves.

This avoids link churn and audit ambiguity while retaining the established cross-business draft workflow.

### 6. Pre-resolve and canonically lock POS split Sale namespaces

POS split planning already resolves each group's `source_setting_id`. Before creating any grouped Sale, the posting path derives every distinct Sale namespace needed by the checkout and sorts namespaces by stable keys: document type, setting ID, prefix, year, and month. It acquires all counter locks in that order, then allocates each group reference and posts all Sales inside the existing checkout transaction.

This includes inline POS checkout and non-stock owner resolution. Each persisted Sale uses the namespace for its actual `setting_id`, not necessarily the terminal setting. A later group failure rolls back all documents and counter increments.

Alternative considered: let each `Sale::create()` model hook acquire its counter ad hoc. Concurrent multi-owner checkouts can encounter owner groups in different orders and create circular lock waits.

### 7. Represent idempotency as an outcome-aware lifecycle

Extend the idempotency abstraction used by Purchase and Sale submissions so a claim can be completed after commit or released/marked failed after rollback. Completed tokens retain the existing duplicate-prevention TTL. Failure cleanup executes in every exception path, including allocation conflicts. POS checkout retains its existing idempotent checkout contract, with regression coverage around allocator rollback.

Alternative considered: always delete the claim in a generic catch. Explicit lifecycle methods make it harder to accidentally release a token after a successful commit followed by a response-layer failure.

### 8. Cut over Purchase and Sale in controlled stages

The schema and generic allocator ship first. A short controlled maintenance step runs dry-run reconciliation, resolves reported blockers, bootstraps counters, and activates all Purchase writers atomically. After Purchase observation, a second controlled cutover repeats reconciliation and activation for all Sale writers, including POS. Old and new allocators are not allowed to operate concurrently for the same document family.

Feature-family activation may use configuration solely as a deployment gate. It must fail closed if the new path is enabled without a valid bootstrapped namespace; it is not a long-lived dual-write mode.

### 9. Provide focused production-parity database verification

Add a test-only Compose file pinned to `mysql:8.0.44`, using a dedicated database whose name ends in `_test`. Configure `REPEATABLE-READ`, the production SQL mode, `utf8mb4`, `utf8mb4_0900_ai_ci`, and Linux UTC+08:00 timezone behavior (`Asia/Kuala_Lumpur` or explicit `+08:00`). A separate PHPUnit configuration must not force SQLite.

The workflow verifies server settings, runs migrations, and executes only dedicated allocator/Purchase/Sale/POS/bootstrap/idempotency tests. Concurrency tests launch independent PHP worker processes against the same schema with a deterministic barrier, bounded timeout, captured diagnostics, and no wrapping `DatabaseTransactions` trait. PHPUnit parallel database isolation is not considered concurrency proof.

## Risks / Trade-offs

- [Bootstrap races with live legacy writers] → Use controlled maintenance during each family cutover and prohibit simultaneous old/new allocation for that family.
- [Malformed legacy references understate counters] → Report and resolve ambiguous rows before activation; never silently coerce malformed values.
- [Multiple POS owner locks deadlock] → Resolve all namespaces first and acquire locks in canonical order; add reverse-owner-order concurrency coverage.
- [A production writer bypasses the allocator] → Inventory call sites, remove independent model algorithms, add architecture/regression tests, and retain database uniqueness constraints.
- [Counter becomes stale after manual database intervention] → Provide monotonic reconciliation and one bounded conflict retry with structured warnings.
- [Nested Laravel transactions obscure ownership] → Define explicit transaction boundaries and assert allocator use within the encompassing transaction; test rollback from outer Purchase, Sale, and POS transactions.
- [Sequence contention reduces throughput] → Locks are per document type/business/prefix/month, so unrelated namespaces proceed independently; measure focused concurrency tests and log lock failures.
- [MySQL test environment becomes stale] → Pin 8.0.44 and assert server version/settings before tests.
- [State leaks between Docker test runs] → Default to disposable storage, guarded `_test` database names, `migrate:fresh`, and cleanup even after failure.

## Migration Plan

1. Add the `document_sequences` migration, typed namespace/allocator services, formatter, reconciliation command, structured logging, and MySQL test harness without activating runtime consumers.
2. Run dry-run reconciliation against an anonymized recent production backup; classify malformed references and verify derived maxima for every Purchase and Sale namespace.
3. Add and pass focused SQLite-compatible behavior tests and scoped MySQL 8.0.44 locking/concurrency tests.
4. Enter a controlled Purchase cutover window, stop Purchase writers, run Purchase bootstrap, enable all Purchase integrations together, smoke test, and resume Purchase traffic.
5. Observe allocation/conflict/idempotency logs and reconcile counters if required. Rollback disables the Purchase activation gate and restores the previous application version; counters remain additive and historical documents remain untouched.
6. Enter a controlled Sale/POS cutover window, stop Sale and POS writers, run Sale bootstrap, enable normal/import/cross-business/POS Sale integrations together, smoke test ordinary and split checkout, then resume traffic.
7. Observe the Sale rollout and remove obsolete Purchase/Sale numbering implementations after the stabilization period.

Database rollback does not drop populated sequence state during an application rollback. The additive table can remain dormant; destructive rollback is reserved for an empty pre-activation environment.

## Open Questions

None. Production database version and settings, initial document-family scope, staged rollout order, and test-suite boundaries were resolved during exploration.

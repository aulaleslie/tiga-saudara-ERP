# Tasks

## 1. Identity and preparation schema

- [x] 1.1 Add shared brand/category normalization and parent namespace resolution; verify focused tests for case, ASCII/Unicode whitespace, empty values, punctuation/spelling differences, and equal names on distinct paths.
- [x] 1.2 Add preparation migrations for nullable canonical fields and durable reconciliation audit storage while retaining legacy ownership; verify fresh SQLite migration and isolated MySQL upgrade fixtures without deleting legacy records.
- [x] 1.3 Implement hierarchy validation and identity calculation with root namespaces and cycle/orphan detection; verify duplicate parents resolve before children and invalid hierarchies produce actionable errors.

## 2. Preview and reference repair

- [x] 2.1 Add a read-only taxonomy reconciliation command that reports lowest-ID survivors, category codes, metadata conflicts, deleted brands, reference counts, unsupported incoming foreign keys, and a plan fingerprint; verify preview leaves all rows unchanged.
- [x] 2.2 Implement explicit confirmed execution with operator identity, deterministic locks, stale-plan checks, transactional product/parent remapping, redundant-row removal, and durable before-state audit; verify exact mappings and survivor metadata on duplicate parent/child fixtures.
- [x] 2.3 Verify rollback after injected mid-merge failure, rejection of stale previews, idempotent reruns, preserved null relations, and unchanged product IDs, unrelated stock/prices/documents, and historical audit payloads using focused reconciliation tests.
- [x] 2.4 Document preview/execution examples, fingerprint review, deleted-brand conflicts, and audit retrieval in a product-taxonomy operations guide; verify examples against command help and fixture execution.

## 3. Global readers, writers, and final constraints

- [x] 3.1 Add guarded schema finalization that requires completed canonical backfill and no conflicts, establishes portable database uniqueness, and removes taxonomy setting ownership columns/foreign keys; verify populated-database upgrades, root uniqueness, deleted-name reservation, business deletion independence, and retry after partial DDL failure.
- [x] 3.2 Update Brand/Category models, controllers, category parent selectors including ProductController, dropdown search and selected-item loading, and quick-add components to use global identity and remove setting ownership; verify focused request/Livewire tests across two businesses, duplicate validation, valid edits, rejected conflicting/cyclic moves, and unchanged permission denial.
- [x] 3.3 Update product import taxonomy resolution to share canonical identity, reuse unique matches, report ambiguous category paths and deleted-brand conflicts, and handle duplicate-key races; verify focused import tests including unique child-name reuse, absent-name root creation, and no arbitrary first-match behavior.
- [x] 3.4 Update affected factories, seeders, and test helpers for the final schema; verify targeted source searches find no remaining product-taxonomy setting filters/writes and focused fixtures still create valid records.
- [x] 3.5 Extend the operations guide with phased deployment, write/worker pause, explicit schema finalization, recovery, backup-based rollback, and cross-business smoke checks; verify the documented sequence on an isolated populated fixture.

## 4. Focused integration verification

- [x] 4.1 Run the focused PHP/Livewire test classes introduced or affected by groups 1–3 using `php artisan test` with explicit paths/filters on the isolated test configuration; record commands and results, broadening only for failures or identified regressions.
- [ ] 4.2 Run isolated MySQL checks for concurrent same-identity creation, collation consistency, root unique keys, SET NULL/CASCADE reference repair, and phased finalization; record results without connecting test execution to the working local database.
- [x] 4.3 Run the delivered read-only preview on the intended local database and compare actual mappings to the observed baseline of 43→32 categories, 29→23 brands, 105 category-reference changes, and 14 brand-reference changes; explain data drift rather than enforcing fixed counts and present the concrete report before cleanup execution.

## Workflow follow-up

- Execute target-database cleanup only after review of the fresh preview and deployment prerequisites; planning and automated fixture checks do not perform that cleanup.
- Archive after implementation and review are complete.

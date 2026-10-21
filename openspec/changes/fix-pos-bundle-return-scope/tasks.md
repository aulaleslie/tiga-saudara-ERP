# Tasks

## 1. Persist scope and resolve original bundles

- [ ] 1.1 Add nullable per-parent bundle_return_scope persistence and allowed-value validation; verify SQLite migration and tests for valid, invalid, new default, and historical null values without history backfill.
- [ ] 1.2 Extend the shared resolver to identify original non-serial split-owner bundles from checkout, POS line, dispatch, carrier, and bundle-item evidence; verify the incident-shaped fixture and tests for ordinary non-bundle sales and ambiguous repeated products.
- [ ] 1.3 Use captured original composition consistently rather than changed catalog composition; verify tests where a catalog bundle is edited or removed after checkout and ensure lookup query growth remains bounded.

## 2. Draft choice and completeness

- [ ] 2.1 Add whole-bundle/default and explicit parent-only controls to shared create/edit surfaces; verify Livewire tests for roundtrip persistence, selected quantities, retained charges, and no action on unselected lines.
- [ ] 2.2 Make submission synthesis and completeness checks scope-aware; verify whole bundle expands proportional components across owners, parent only excludes component execution, and individual component cash requests remain rejected.
- [ ] 2.3 Require reviewed scope for legacy pending bundle drafts through the existing audited rejection/edit/resubmit workflow; verify missing-scope approval is blocked while historical completed returns remain unchanged.
- [ ] 2.4 Document the two choices and their charge consequences; verify examples show Rp890,000 whole-bundle reduction versus Rp630,000 parent-only reduction with Rp260,000 retained.

## 3. Preview and atomic execution

- [ ] 3.1 Carry scope into approval plans and detail/audit display with included/retained composition, owner lineage, actual refund, and debt reduction; verify preview read-only tests and stale-intent rejection.
- [ ] 3.2 Execute whole-bundle reversals across parent/component owner sales and execute parent-only reversals at the parent allocation; verify non-serial incident-shaped and same-owner lifecycle tests including stockless components.
- [ ] 3.3 Preserve retained component totals, debt, cost evidence, dispatch obligations, and sale status after parent-only return; verify no sale with remaining component obligations is zero-settled or archived as fully returned.
- [ ] 3.4 Verify paid, unpaid, partially paid, credit-settled, tax, and discount cases reconcile to original allocations without duplicate refunds; add focused settlement tests in the same implementation group.
- [ ] 3.5 Verify quantity limits, serial parent/component eligibility, successive partial returns, retries, and cross-owner failure rollback; confirm existing replacement tests remain unchanged.

## 4. Audited historical correction

- [ ] 4.1 Implement a dry-run-first correction plan for TNC-TXN-2026-10-0638 with exact source identities and missing-component evidence; verify no database writes on dry run and rejection of uncertain, unrelated, or already manually repaired evidence.
- [ ] 4.2 Add a linked corrective record and narrowly scoped atomic execution with stable idempotency identity, actor, reason, physical-return confirmation, locks, before/after audit, and existing stock/commercial/cost/settlement services; verify conflict, failure rollback, duplicate prevention, and no camera replay.
- [ ] 4.3 Document correction invocation, physical-return evidence, apply prerequisites, and post-checks; verify the tested usage matches command help and reports Rp260,000 debt reduction with zero cash payout for the original unpaid fixture.
- [ ] 4.4 Run read-only correction discovery on the local dataset and retain a reviewable report; verify the source still matches return 111 and component 900/dispatch 424502 without applying the correction.

## 5. Integration verification

- [ ] 5.1 Run focused POS Return bundle, draft, preview, lifecycle, settlement, serial, and repair suites using php artisan test or composer test:fresh-sqlite; record results and investigate related regressions.
- [ ] 5.2 Validate OpenSpec artifacts and inspect the final diff for application changes limited to the scoped behavior; verify no unintended database mutation or unrelated working-tree overwrite.

## Workflow follow-up

- Review the correction dry-run, then execute the explicit apply operation only with recorded physical-return confirmation, actor, and reason; verify incident debt is zero and exactly one microSD reversal was appended.
- Archive after implementation and review are complete.

# Tasks

## 1. Persist scope and resolve original bundles

- [x] 1.1 Add nullable per-parent bundle_return_scope persistence and allowed-value validation; verify SQLite migration and tests for valid, invalid, new default, and historical null values without history backfill.
- [x] 1.2 Extend the shared resolver to identify original non-serial split-owner bundles from checkout, POS line, dispatch, carrier, and bundle-item evidence; verify the incident-shaped fixture and tests for ordinary non-bundle sales and ambiguous repeated products.
- [x] 1.3 Use captured original composition consistently rather than changed catalog composition; verify tests where a catalog bundle is edited or removed after checkout and ensure lookup query growth remains bounded.

## 2. Draft choice and completeness

- [x] 2.1 Add whole-bundle/default and explicit parent-only controls to shared create/edit surfaces; verify Livewire tests for roundtrip persistence, selected quantities, retained charges, and no action on unselected lines.
- [x] 2.2 Make submission synthesis and completeness checks scope-aware; verify whole bundle expands proportional components across owners, parent only excludes component execution, and individual component cash requests remain rejected.
- [x] 2.3 Require reviewed scope for legacy pending bundle drafts through the existing audited rejection/edit/resubmit workflow; verify missing-scope approval is blocked while historical completed returns remain unchanged.
- [x] 2.4 Document the two choices and their charge consequences; verify examples show Rp890,000 whole-bundle reduction versus Rp630,000 parent-only reduction with Rp260,000 retained.

## 3. Preview and atomic execution

- [x] 3.1 Carry scope into approval plans and detail/audit display with included/retained composition, owner lineage, actual refund, and debt reduction; verify preview read-only tests and stale-intent rejection.
- [x] 3.2 Execute whole-bundle reversals across parent/component owner sales and execute parent-only reversals at the parent allocation; verify non-serial incident-shaped and same-owner lifecycle tests including stockless components.
- [x] 3.3 Preserve retained component totals, debt, cost evidence, dispatch obligations, and sale status after parent-only return; verify no sale with remaining component obligations is zero-settled or archived as fully returned.
- [x] 3.4 Verify paid, unpaid, partially paid, credit-settled, tax, and discount cases reconcile to original allocations without duplicate refunds; add focused settlement tests in the same implementation group.
- [x] 3.5 Verify quantity limits, serial parent/component eligibility, successive partial returns, retries, and cross-owner failure rollback; confirm existing replacement tests remain unchanged.

## 4. Audited historical correction

- [x] 4.1 Implement a dry-run-first correction plan for TNC-TXN-2026-10-0638 with exact source identities and missing-component evidence; verify no database writes on dry run and rejection of uncertain, unrelated, or already manually repaired evidence.
- [x] 4.2 Add a linked corrective record and narrowly scoped atomic execution with stable idempotency identity, actor, reason, physical-return confirmation, locks, before/after audit, and existing stock/commercial/cost/settlement services; verify conflict, failure rollback, duplicate prevention, and no camera replay.
- [x] 4.3 Document correction invocation, physical-return evidence, apply prerequisites, and post-checks; verify the tested usage matches command help and reports Rp260,000 debt reduction with zero cash payout for the original unpaid fixture.
- [x] 4.4 Run read-only correction discovery on the local dataset and retain a reviewable report; verify the source still matches return 111 and component 900/dispatch 424502 without applying the correction.

## 5. Integration verification

- [x] 5.1 Run focused POS Return bundle, draft, preview, lifecycle, settlement, serial, and repair suites using php artisan test or composer test:fresh-sqlite; record results and investigate related regressions.
- [x] 5.2 Validate OpenSpec artifacts and inspect the final diff for application changes limited to the scoped behavior; verify no unintended database mutation or unrelated working-tree overwrite.

## Known baseline failures

These 8 tests fail both in the working tree and on clean `HEAD` (aecfc9e1), so they are not regressions from this change. Monetary behavior is intentionally left unchanged here; fix them in a separate change.

| File | Working tree | Clean `HEAD` |
|---|---|---|
| `POSReturnApprovalPreviewPlannerTest` | 4 failures | same 4 failures |
| `POSReturnAtomicLifecycleTest` | 3 failures | same 3 failures + 4 errors (fixed by this change) |
| `POSReturnBundleComponentSerialLineageTest` | 1 failure | same 1 failure |

- `POSReturnApprovalPreviewPlannerTest::it_maps_component_targets_from_sale_detail_linked_rows_using_return_line_share`: false `snapshot_drift` blocker from proration rounding (6200 vs 6000).
- `POSReturnApprovalPreviewPlannerTest::it_reports_a_blocker_when_a_bundle_component_target_is_missing`: emits `bundle_component_missing`; test expects a different code.
- `POSReturnApprovalPreviewPlannerTest::it_reports_a_blocker_when_a_bundle_component_target_is_ambiguous`: emits `bundle_component_missing`; test expects an ambiguity code.
- `POSReturnApprovalPreviewPlannerTest::it_calculates_proportional_bundle_component_quantities_for_partial_parent_returns`: component quantity 1.0, expected 2.0.
- `POSReturnAtomicLifecycleTest::it_uses_expected_cash_amount_as_sale_detail_monetary_source_of_truth_when_it_differs_from_proration`: 960.0, expected 600.0.
- `POSReturnAtomicLifecycleTest::it_recalculates_source_sale_totals_after_cash_return_corrections`: 920.0, expected 560.0.
- `POSReturnAtomicLifecycleTest::it_splits_or_invalidates_active_sale_payments_last_payment_first_after_cash_return_corrections`: 800.0, expected 560.0.
- `POSReturnBundleComponentSerialLineageTest::it_prevents_a_second_return_from_synchronizing_a_plan_that_claims_an_already_claimed_component_serial`: quantity-limit guard fires before the serial-claim check.

### Changed outcomes of baseline tests (tracked separately)

Two of the eight baseline tests above were rewritten in the second review round because their old assertions encoded silent component skipping, which this change now forbids. They are no longer baseline failures; the remaining six are unchanged.

- `POSReturnApprovalPreviewPlannerTest::it_reports_a_blocker_when_a_bundle_component_target_is_missing`: was asserting an unblocked parent-only plan. Now builds the pending return directly (submission rejects this source data) and asserts a `bundle_component_allocation_unresolved` blocker with `lineage_status = missing` that names the component. Passes.
- `POSReturnApprovalPreviewPlannerTest::it_reports_a_blocker_when_a_bundle_component_target_is_ambiguous`: was asserting an unblocked parent-only plan. Fixture now has two indistinguishable persisted bundle items for the component; builds the pending return directly and asserts the same blocker with `lineage_status = ambiguous`. Passes.
- Intermediate state, for the record: after the first round's resolver change (before these rewrites) both tests errored at submission with the generic "harus mencakup seluruh unit bundel" message instead of failing their assertions.

## Resolved regression

- `POSReturnDraftBundleScopeTest::it_synthesizes_split_owner_components_for_whole_bundle_and_handles_parent_only_reduction`: a standalone cash return for a priced split-owner component row (Rp260,000) was accepted instead of rejected (task 2.2). Fixed: `PosReturnSubmissionService::isBundleComponentAllocationRow()` now identifies component allocation rows from persisted lineage (zero-priced sibling, bundle-tagged dispatch, or a carrier `SaleBundleItem` matching the row's quantity and allocated price), so same-SKU standalone sales stay valid. Both `POSReturnDraftBundleScopeTest` and `POSReturnBundleCashReturnCompletenessTest` pass.

### Verification sequence

1. Full POS Return run, before the fix: 3 files with only the 8 baseline failures, 1 regression file (`POSReturnDraftBundleScopeTest`), the rest fully passing. (Originally reported as 52 files; the actual file set is 51 — all `POSReturn*` files plus `POSHistoricalBundleCorrectionTest` and `POSReceiptReturnProjectionTest` — so the passing count was 47, not 48.)
2. Localized fix applied to `PosReturnSubmissionService`.
3. Affected-suite rerun after the fix: `POSReturnDraftBundleScopeTest`, `POSReturnBundleCashReturnCompletenessTest`, the three files holding the review regression tests, `POSReturnBundleCarrierRowCashReturnCompletenessTest`, `POSReturnSubmissionTest`, `POSReturnBundleRegressionTest`, `POSReturnSplitOwnerCarrierRowRegressionTest` — all pass. The 3 baseline files still show exactly the same 8 failures. The full 52-file suite was not rerun after the fix.

### Known limitations — awaiting human acceptance

1. **Quantity/price carrier fallback.** The split-owner carrier fallback in `isBundleComponentAllocationRow()` matches on the carrier `SaleBundleItem`'s quantity and allocated price. It is not proven component identity. A legitimate standalone sale in a split-owner Sale with exactly that quantity and price is treated as a component row, and its standalone cash return is rejected.
2. **Ambiguous same-product identity across Sales.** When the same product is sold both standalone and as a bundle parent in one checkout, the rows sit in different owner Sales, and neither row has row-level bundle evidence (its own `SaleBundleItem`s or a bundle-tagged dispatch), `PosBundleComponentResolver` reports the identity as ambiguous. Any cash return of either row — including the legitimate standalone sale — is then blocked with an actionable error instead of being resolved by row order. Covered by `POSReturnOriginalBundleResolutionTest::it_flags_ambiguity_when_same_product_ordinary_and_bundle_rows_span_sales_without_row_evidence`.

Both fail safe (block rather than drop components) but can block legitimate returns. **Status: awaiting human acceptance.** Accepting them does not resolve any other finding.

## Review findings verification

Each of the four review findings has a permanent regression test covering a variant beyond the implementer's original reproduction; all pass.

1. Genuine bundle classified as ordinary: `POSReturnOriginalBundleResolutionTest` — bundle line before ordinary line; ordinary and bundle in separate sales.
2. Monetary changes bypass renewed review: `POSReturnBundleScopeLifecycleExecutionTest::whole_bundle_approval_blocks_and_rejects_when_component_allocation_changes_after_submission`.
3. Historical correction duplicate receiving: `POSHistoricalBundleCorrectionTest::duplicate_return_check_detects_separate_return_linked_only_by_component_dispatch`.
4. Historical correction trusts cached payment totals: `POSHistoricalBundleCorrectionTest::payment_ledger_check_detects_credit_application_even_when_sale_header_is_stale`.

## Second review round findings

1. **[P1] Bundle identity depended on row order.** `PosBundleComponentResolver` now decides identity from row-level evidence first (the row's own `SaleBundleItem`s or a stock-managed dispatch for that exact row with a `bundle_id`). Positional pairing is used only when every candidate sits in one Sale; across Sales without row evidence the identity is ambiguous and blocked (limitation 2 above). Tests: `POSReturnOriginalBundleResolutionTest::it_resolves_bundle_parent_from_bundle_dispatch_when_sale_row_order_differs_from_pos_line_order`, `..._span_sales_without_row_evidence`, `..._are_in_separate_sales`.
2. **[P1] Archived returns escaped correction checks.** `PosHistoricalBundleCorrectionService` discovery and apply now include archived Sales Returns in the duplicate-return check. Test: `POSHistoricalBundleCorrectionTest::duplicate_return_check_includes_archived_completed_component_return`.
3. **[P2] Catalog edits blocked valid historical returns.** Composition captured at checkout (POS line `bundle_items`, else persisted `SaleBundleItem` rows from the row itself or a single carrier) is authoritative. The resolver never substitutes today's catalog: with no captured composition it returns an actionable "Komposisi asli paket bundel … tidak dapat dipastikan" error. A captured component whose source allocation is missing or ambiguous is rejected by name at submission (`describeUnresolvedComponent()`), blocked by the planner (`bundle_component_allocation_unresolved`, or `bundle_lineage_unresolved` for unresolved composition), and refused at execution. The silent `filterResolvableFallbackComponents()` skip was removed. Tests: `POSReturnDraftBundleScopeTest` (catalog adds a component, with and without POS-line capture), `POSReturnSplitOwnerCarrierRowRegressionTest::it_accepts_a_whole_bundle_cash_return_when_a_catalog_component_was_added_after_checkout` and `::it_rejects_a_whole_bundle_cash_return_when_a_sold_component_allocation_cannot_be_resolved` (replacing the old catalog-based rejection test), `POSReturnSubmissionTest::it_rejects_bundle_return_naming_components_whose_source_allocation_cannot_be_resolved` (replacing `it_can_submit_return_for_bundle_pos_lines`).

## Workflow follow-up

**Status (2026-10-23):** historical correction remains **unapplied**. Apply only after physical microSD return is confirmed and the dry-run is reviewed. Expected result: **Rp260,000 debt reduction, zero cash payout**. Manual review, including acceptance of both known limitations above, must be completed before archiving.


- Review the correction dry-run, then execute the explicit apply operation only with recorded physical-return confirmation, actor, and reason; verify incident debt is zero and exactly one microSD reversal was appended.
- Archive after implementation and review are complete.

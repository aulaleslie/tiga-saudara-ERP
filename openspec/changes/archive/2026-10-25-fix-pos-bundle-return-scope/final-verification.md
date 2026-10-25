# Final code verification — 2026-10-23

Verified commit: `b11259c9d7c244dfa619e4463936cf7a7dfb346f`.

## Result

The previous three blocking findings are resolved in the reviewed paths, supported by permanent regression coverage and three independently rerun probes. Verification is not completely green: six documented baseline failures remain, one test still asserts the obsolete generic error message, and the correction command has an already-corrected output defect described below. The two documented behavior limitations still await human acceptance. No change was archived.

## Execution and scope

- Enumerated 51 module files: every `POSReturn*Test.php`, `POSHistoricalBundleCorrectionTest.php`, and `POSReceiptReturnProjectionTest.php`.
- Added both `tests/Feature/Livewire/PosReturn/*Test.php` files: 53 files in the completed run.
- Started with fresh SQLite and separate processes per file. After 12 completed files, stopped that preliminary run because it repeatedly rebuilt the entire schema.
- Completed a fresh, explicit 53-suite PHPUnit run in one process, each suite containing one file, in the listed order. Execution was sequential with no parallel workers. Preliminary results were not mixed into final totals.
- Temporary configuration copied the repository's PHPUnit settings, supplied absolute bootstrap/test paths, and omitted unused coverage configuration. Command: `./vendor/bin/phpunit -c /tmp/pos-final-verification/phpunit.xml`.
- Completed run: **367 tests, 2,095 assertions; 360 passed, 7 failed; no errors**. Duration: 83.681 seconds.
- By file: **49 of 53 fully pass**; three files contain the six baseline failures; one file has the obsolete-message assertion. Within the module-only set this is 47 of 51 fully passing; both additional Livewire files pass.
- PHP syntax: all 30 changed non-Blade PHP files pass `php -l`.
- `openspec validate fix-pos-bundle-return-scope --strict` passes.
- Working tree was clean during application-code verification. Only this report and its evidence files were added afterward.

Full test output is retained in [final-verification-tests.txt](final-verification-tests.txt). Independent probe output is retained in [final-verification-probes.txt](final-verification-probes.txt).

## Remaining test outcomes

Six failures match the baseline outcomes previously compared with clean HEAD; that historical comparison was not repeated in this verification:

1. Planner: `it_maps_component_targets_from_sale_detail_linked_rows_using_return_line_share` — snapshot drift 6000 versus 6200.
2. Planner: `it_calculates_proportional_bundle_component_quantities_for_partial_parent_returns` — component quantity 1 versus 2.
3. Atomic lifecycle: `it_uses_expected_cash_amount_as_sale_detail_monetary_source_of_truth_when_it_differs_from_proration` — 960 versus 600.
4. Atomic lifecycle: `it_recalculates_source_sale_totals_after_cash_return_corrections` — 920 versus 560.
5. Atomic lifecycle: `it_splits_or_invalidates_active_sale_payments_last_payment_first_after_cash_return_corrections` — 800 versus 560.
6. Serial lineage: `it_prevents_a_second_return_from_synchronizing_a_plan_that_claims_an_already_claimed_component_serial` — quantity-limit validation precedes serial-claim validation.

Additional failure: `POSReturnBundleRegressionTest::it_correctly_groups_serialized_bundled_products_by_pos_transaction_line`, line 288, expects `harus mencakup seluruh unit bundel`. Submission correctly rejects the missing sold component with the approved component-specific error naming `Comp 2` and its missing allocation. Update this assertion and its catalog-authority comment to the approved original-composition rule; do not change the application back to the generic message or allow the missing component.

The rewritten planner missing/ambiguous-source tests pass.

## Previous blocking findings

Three independent probes, run after the full suite, pass (3 tests, 4 assertions):

- A genuine bundle parent with an exact bundle-tagged dispatch still resolves when owner sale-detail order differs from POS line order.
- Adding a catalog-only component after checkout does not prevent returning the original captured bundle.
- An archived, completed component Sales Return blocks the historical correction.

The full suite also includes permanent coverage for component monetary drift, separate received-return detection, active-credit ledger validation, stored-rows-only composition, individual-component cash rejection, and source ambiguity.

## Remaining command reporting defect

`Modules/Pos/Services/PosHistoricalBundleCorrectionService.php:865`: `buildCandidate()` suppresses hypothetical stock increments only for classification `repaired`, not `already_corrected`.

Observed against the local MySQL data: dry run returns `ALREADY_CORRECTED` but predicts location stock 42 → 43 and global stock 47 → 48. No additional movement is actually performed. Already-corrected discovery and apply responses should report current stock unchanged. This is a reporting defect, not evidence of a second stock increment. No application fix was made during verification.

## Actual local incident state

Contrary to the earlier handover's “unapplied” status, read-only local checks found:

- Corrective Sales Return **136**, reference **TNC-SLRN-CORR-2610-0111**, linked to POS Return 111 and component Sale 210539.
- Corrective detail 176: microSD quantity 1, original bundle-item 900, dispatch-detail 424502.
- Source Sale 210539: total, paid, and due amounts are zero; status RETURNED.
- Dispatch-detail 424502: remaining dispatched quantity zero.
- One corrective inventory transaction, ID 32398: location 6 stock 41 → 42.
- Zero Sale Return Payment records for correction 136; zero cash payout.
- Audit records name actor 1 and record physical-return confirmation and a reason. These are stored claims; this verification does not independently confirm the physical return.
- Recorded execution timestamp: `2026-10-23 16:40:44` (database value).

Only dry-run and SELECT queries were executed against local MySQL during verification. No apply operation was executed. The original saved snapshot was not rewritten. Production state was not checked.

## Human review still required

Both limitations in tasks.md remain awaiting explicit acceptance: quantity/price carrier matching can block a standalone return, and unprovable cross-sale same-product identity is blocked. This report does not accept them on the user's behalf.

## Completed run file inventory

- `Modules/Pos/Tests/Feature/POSHistoricalBundleCorrectionTest.php`
- `Modules/Pos/Tests/Feature/POSReceiptReturnProjectionTest.php`
- `Modules/Pos/Tests/Feature/POSReturnApprovalPlanPersistenceTest.php`
- `Modules/Pos/Tests/Feature/POSReturnApprovalPreviewBundleLineageTest.php`
- `Modules/Pos/Tests/Feature/POSReturnApprovalPreviewBundleScopeTest.php`
- `Modules/Pos/Tests/Feature/POSReturnApprovalPreviewMutationSafetyTest.php`
- `Modules/Pos/Tests/Feature/POSReturnApprovalPreviewPlannerTest.php`
- `Modules/Pos/Tests/Feature/POSReturnApprovalPreviewRouteTest.php`
- `Modules/Pos/Tests/Feature/POSReturnApprovalWorkflowTest.php`
- `Modules/Pos/Tests/Feature/POSReturnArchiveCancelWorkflowTest.php`
- `Modules/Pos/Tests/Feature/POSReturnAtomicLifecycleTest.php`
- `Modules/Pos/Tests/Feature/POSReturnAuditTrailTest.php`
- `Modules/Pos/Tests/Feature/POSReturnBundleCarrierRowCashReturnCompletenessTest.php`
- `Modules/Pos/Tests/Feature/POSReturnBundleCashReturnCompletenessTest.php`
- `Modules/Pos/Tests/Feature/POSReturnBundleComponentSerialLineageTest.php`
- `Modules/Pos/Tests/Feature/POSReturnBundleLifecycleExecutionTest.php`
- `Modules/Pos/Tests/Feature/POSReturnBundleRegressionTest.php`
- `Modules/Pos/Tests/Feature/POSReturnBundleScopeLifecycleExecutionTest.php`
- `Modules/Pos/Tests/Feature/POSReturnBundleScopeLivewireFormTest.php`
- `Modules/Pos/Tests/Feature/POSReturnCostReversalTest.php`
- `Modules/Pos/Tests/Feature/POSReturnCrossOwnerReplacementTest.php`
- `Modules/Pos/Tests/Feature/POSReturnDispatchQuantityAdjustmentTest.php`
- `Modules/Pos/Tests/Feature/POSReturnDraftBundleScopeTest.php`
- `Modules/Pos/Tests/Feature/POSReturnDraftListActionsTest.php`
- `Modules/Pos/Tests/Feature/POSReturnDraftResolutionVerificationTest.php`
- `Modules/Pos/Tests/Feature/POSReturnDraftingTest.php`
- `Modules/Pos/Tests/Feature/POSReturnLegacyPendingScopeBlockerTest.php`
- `Modules/Pos/Tests/Feature/POSReturnLifecycleGuardTest.php`
- `Modules/Pos/Tests/Feature/POSReturnLookupPerformanceTest.php`
- `Modules/Pos/Tests/Feature/POSReturnLookupTest.php`
- `Modules/Pos/Tests/Feature/POSReturnManualCorrectionWorkflowTest.php`
- `Modules/Pos/Tests/Feature/POSReturnModelRelationshipTest.php`
- `Modules/Pos/Tests/Feature/POSReturnOriginalBundleResolutionTest.php`
- `Modules/Pos/Tests/Feature/POSReturnPaymentReturnWorkflowTest.php`
- `Modules/Pos/Tests/Feature/POSReturnPermissionMatrixTest.php`
- `Modules/Pos/Tests/Feature/POSReturnQuantityGuardTest.php`
- `Modules/Pos/Tests/Feature/POSReturnReadonlyDetailViewTest.php`
- `Modules/Pos/Tests/Feature/POSReturnReceivingWorkflowTest.php`
- `Modules/Pos/Tests/Feature/POSReturnReplacementDispatchWorkflowTest.php`
- `Modules/Pos/Tests/Feature/POSReturnReplacementHppReleaseGateTest.php`
- `Modules/Pos/Tests/Feature/POSReturnReplacementSourceConstraintTest.php`
- `Modules/Pos/Tests/Feature/POSReturnReplacementStockBucketTest.php`
- `Modules/Pos/Tests/Feature/POSReturnResoldSerialTest.php`
- `Modules/Pos/Tests/Feature/POSReturnRouteAuthorizationTest.php`
- `Modules/Pos/Tests/Feature/POSReturnSerialSplitOwnerTest.php`
- `Modules/Pos/Tests/Feature/POSReturnSettlementWorkflowTest.php`
- `Modules/Pos/Tests/Feature/POSReturnSharedFormSurfaceTest.php`
- `Modules/Pos/Tests/Feature/POSReturnSnapshotStalenessTest.php`
- `Modules/Pos/Tests/Feature/POSReturnSplitOwnerCarrierRowRegressionTest.php`
- `Modules/Pos/Tests/Feature/POSReturnSplitOwnerMappingTest.php`
- `Modules/Pos/Tests/Feature/POSReturnSubmissionTest.php`
- `tests/Feature/Livewire/PosReturn/PosReturnCreateFormTest.php`
- `tests/Feature/Livewire/PosReturn/PosReturnEditFormTest.php`

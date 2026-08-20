# Bundle Release Manifest

Focused regression gate for enabling product bundles in production. Only the
files below are required release evidence; broader module/application suites
are optional diagnostics and are not part of this gate.

## How to run

```bash
php artisan test --filter="<ClassName1|ClassName2|...>"
```

or target specific files directly:

```bash
php artisan test <path1> <path2> ...
```

## 1. Bundle definition, lifecycle, and captured pricing

- `Modules/Product/Tests/Feature/ProductBundleDefinitionIntegrityTest.php`
- `Modules/Product/Tests/Unit/ProductBundleSnapshotMapperTest.php`
- `tests/Feature/ProductBundlePricingTest.php`
- `Modules/Pos/Tests/Feature/PosBundleCapturedPricingAndAllocationTest.php`
- `tests/Feature/SaleBundleLifecycleWarningTest.php`

## 2. Normal Sales and POS split persistence

- `tests/Feature/NormalSalesBundlePersistenceTest.php`
- `Modules/Sale/Tests/Feature/SaleBundleCostSnapshotTest.php`
- `Modules/Sale/Tests/Feature/SaleBundleItemCostSnapshotPersistenceTest.php`
- `Modules/Pos/Tests/Feature/PosSplitBundleCostSnapshotTest.php`
- `Modules/Pos/Tests/Feature/POSCheckoutSplitPostingTest.php`

## 3. Dispatch, serial, and receipt identity

- `Modules/Sale/Tests/Feature/DispatchApprovalTest.php`
- `Modules/Sale/Tests/Feature/DispatchBundleComponentSerialRegressionTest.php`
- `Modules/Pos/Tests/Feature/POSReturnBundleComponentSerialLineageTest.php`
- `Modules/Pos/Tests/Feature/POSSplitSerialBundleCheckoutTest.php`
- `Modules/Pos/Tests/Feature/POSSplitBundleReceiptReconstructionTest.php`
- `Modules/Sale/Tests/Feature/SaleShowSerialBadgeTest.php`

## 4. Owner-aware HPP and reports

- `Modules/Sale/Tests/Unit/AverageCostResolverTest.php`
- `tests/Feature/Reports/SaleHppAggregateServiceTest.php`
- `tests/Feature/Reports/SaleHppAggregateReplacementTest.php`
- `Modules/Sale/Tests/Feature/SaleByProductReportTest.php`
- `Modules/Sale/Tests/Feature/SaleDeliveryReportTest.php`

## 5. Return reversal and replacement HPP

- `Modules/Pos/Tests/Feature/POSReturnCostReversalTest.php`
- `Modules/Pos/Tests/Feature/POSReturnReplacementHppReleaseGateTest.php`
- `Modules/SalesReturn/Tests/Feature/SaleReturnDetailCostReversalPersistenceTest.php`

## 6. Standard and POS full/partial return lifecycle (this change)

- `Modules/SalesReturn/Tests/Feature/SaleReturnReceiveSerialStatusTest.php`
- `Modules/SalesReturn/Tests/Feature/SaleReturnLifecycleCoverageServiceTest.php`
- `Modules/Pos/Tests/Feature/POSReturnAtomicLifecycleTest.php`
- `Modules/Pos/Tests/Feature/POSReturnAuditTrailTest.php`
- `Modules/Pos/Tests/Feature/POSReturnArchiveCancelWorkflowTest.php`
- `Modules/Pos/Tests/Feature/POSReturnCrossOwnerReplacementTest.php`

## 7. Idempotent finalization and replacement

- `Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php`
- `Modules/Sale/Tests/Feature/BackfillSaleBundleItemCostSnapshotsCommandTest.php`

## 9. PKP tax-bucket invariant (non-PKP/PKP stock-bucket compatibility)

- `Modules/Pos/Tests/Feature/POSStockAllocationResolverTest.php`
- `Modules/Pos/Tests/Unit/PosCheckoutSplitPlannerServiceTest.php`
- `Modules/Pos/Tests/Feature/POSMixedOwnerTaxBySourceTest.php`
- `Modules/Pos/Tests/Feature/SplitBundleTransactionTest.php`

## 8. Migration compatibility

Additive up/rollback verification for the touched bundle/HPP/return schema
migrations (see `migration-verification.md` and
`tests/Feature/BundleReleaseMigrationCompatibilityTest.php`):

- `Modules/Sale/Database/Migrations/2026_08_21_032552_add_cost_snapshot_columns_to_sale_bundle_items_table.php`
- `Modules/Sale/Database/Migrations/2026_08_21_044029_add_replacement_cost_snapshot_columns_to_dispatch_details_table.php`
- `Modules/SalesReturn/Database/Migrations/2026_08_21_032600_add_cost_reversal_columns_to_sale_return_details_table.php`
- `Modules/SalesReturn/Database/Migrations/2026_08_21_051825_add_commercial_quantity_also_reduced_to_sale_return_details_table.php`

## Failure classification policy

Every failure observed while running this manifest must be classified as one
of: implementation defect, test-fixture defect, assertion defect,
environmental issue, or confirmed flaky test. An implementation defect or an
unexplained failure blocks production bundle enablement. See
`release-result.md` for the recorded classification of the current run.

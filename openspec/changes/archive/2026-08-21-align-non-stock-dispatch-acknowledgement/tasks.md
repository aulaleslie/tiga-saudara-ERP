## 1. Characterize Fulfillment Acknowledgement

- [x] 1.1 Add focused Standard Sales dispatch coverage proving approved non-stock parent quantity contributes to partial/full completion without changing product stock, location stock, serial state, or inventory transactions.
- [x] 1.2 Add focused bundle coverage proving a non-stock parent and non-stock component create audit-only acknowledgement rows while a stock-managed component independently creates exactly one inventory effect per approved quantity.
- [x] 1.3 Add rejection/resubmission coverage proving rejected stock and non-stock acknowledgement quantities do not count toward completion and become available for a new dispatch.

## 2. Protect Bundle Fulfillment Identity

- [x] 2.1 Extend dispatch composite-key regression coverage for the same SKU sold standalone and inside a bundle, the same component used by two distinct bundles, and the same component under different tax buckets.
- [x] 2.2 Add coverage for the same bundle definition persisted as separate transaction rows, proving equivalent demand aggregates without duplicate inventory movement.
- [x] 2.3 Add partial-dispatch coverage across permitted locations, proving pending plus approved quantities never exceed aggregate demand and each approved location quantity produces one inventory effect.
- [x] 2.4 If and only if a focused identity regression test fails, make the smallest correction required in dispatch aggregation, reservation, or approval; do not add `sale_detail_id`, `line_group_key`, or another schema key without a demonstrated collision.

## 3. Preserve Completed-Work Reporting

- [x] 3.1 Add focused Sales Delivery report coverage proving approved non-stock acknowledgement quantity is included as completed work and pending/rejected acknowledgement quantity is excluded.
- [x] 3.2 Add a mixed bundle report regression proving standalone, bundle, and tax contexts remain separated by the existing Sale/product/tax/bundle composite key.
- [x] 3.3 If and only if a focused reporting regression fails, make the smallest query correction while preserving approved non-stock inclusion and the existing composite key.

## 4. Production-Safety Verification

- [x] 4.1 Confirm the implementation introduces no migration, backfill, repair command, historical dispatch mutation, or Sale-status recalculation.
- [x] 4.2 Run only the affected dispatch tests and related regressions: `DispatchCompositeKeyTest`, `DispatchApprovalTest`, `DispatchNonStockProductsTest`, `SalesDispatchBundleTaxInheritanceTest`, and the directly affected Sales Delivery report tests.
- [x] 4.3 Record non-stock service-return prohibition as deferred to Sequence 10 and verify no Sales Return or POS Return behavior is changed by this implementation.


# Tasks

## 1. Replacement Stock Mutation

- [x] 1.1 Add a shared replacement-dispatch stock mutation that resolves the movement owner through `locations.setting_id`, selects `quantity_tax` for PKP or `quantity_non_tax` for non-PKP, locks and validates the selected bucket, and verify focused service tests cover both bucket choices
- [x] 1.2 Route same-owner and cross-owner POS Return replacement dispatch through the shared mutation without consulting POS configuration or deprecated `products.setting_id`, and verify focused tests preserve both execution modes and all valid cross-owner combinations
- [x] 1.3 Recompute aggregate and broken quantities from their four condition/tax buckets, maintain global `products.product_quantity`, and verify stock and sellable serial counts reconcile after replacement dispatch
- [x] 1.4 Record the actual replacement owner setting, location, selected bucket, and accurate global/location previous and after balances in the outbound transaction ledger, and verify focused ledger assertions for PKP and non-PKP dispatch
- [x] 1.5 Preserve stockless bundle behavior and atomic rollback when the required owner bucket is insufficient, and verify focused stockless and insufficient-bucket tests have no partial inventory, serial, dispatch, or ledger effects

## 2. Evidence-Gated Historical Repair

- [x] 2.1 Implement read-only candidate discovery from completed managed POS replacement lines, linked Sale Return details, replacement dispatches, `DISPATCH_RETURN` transactions, owner settings, and serial lineage, and verify fixtures classify the two confirmed rows while excluding product 4014
- [x] 2.2 Implement dry-run output with product, owner/location, current and expected buckets, aggregate and bucket deltas, classification, POS Return/Sale Return/dispatch/transaction IDs, and serial evidence, and verify the default invocation performs no writes
- [x] 2.3 Implement guarded apply with row locks, evidence revalidation, exact current-value preconditions, and per-row transactions, and verify a post-dry-run stock change produces a conflict without overwrite
- [x] 2.4 Create explicit corrective transaction/audit evidence with a stable repair identity while leaving existing returns, dispatches, transactions, and serial histories immutable, and verify a second apply creates no stock change or duplicate audit record
- [x] 2.5 Register the dedicated Artisan command with an explicit apply option and optional safe candidate filtering, and verify command-level dry-run, apply, error, and summary exit behavior

## 3. Focused Regression Verification

- [x] 3.1 Add a non-tax serialized lifecycle test and a tax serialized lifecycle test that assert aggregate quantity, all buckets, global product quantity, sellable serial count, and ledger balances after receipt and replacement
- [x] 3.2 Add focused cross-owner tests for PKP original/non-PKP replacement and non-PKP original/PKP replacement, and verify the original owner bucket increments while the replacement owner bucket decrements independently
- [x] 3.3 Add the return → replacement → resale → second return → second replacement regression and assert stock, bucket, serial, and ledger state after every stage
- [x] 3.4 Run only the focused POS Return replacement and repair command/service test filters, document their passing results, and do not schedule or require the full test suite

### 3.4 Focused verification results

Run serially with `php artisan test --filter <suite>`:

| Suite | Result |
| --- | --- |
| `POSReturnReplacementStockBucketTest` (new) | 5 passed (41 assertions) |
| `PosRepairReturnReplacementStockBucketsCommandTest` (new) | 7 passed (46 assertions) |
| `POSReturnCrossOwnerReplacementTest` | 16 passed (47 assertions) |
| `POSReturnReplacementDispatchWorkflowTest` | 7 passed (21 assertions) |
| `POSReturnReplacementHppReleaseGateTest` | 2 passed (10 assertions) |
| `POSReturnReplacementSourceConstraintTest` | 1 passed (2 assertions) |

Total: 38 passed. No full-suite run was performed or is required.

### Real-data dry-run (production database)

`php artisan pos:repair-return-replacement-stock-buckets` reports exactly the
two confirmed rows and nothing else:

| Row | Classification | Correction | Evidence |
| --- | --- | --- | --- |
| product 182 / location 6 | repairable | `quantity_non_tax` 49 → 47, aggregate 48 → 47 | POS Returns 84, 86; transactions 17381, 17538 |
| product 4391 / location 2 | repairable | `quantity_non_tax` 4 → 3, aggregate unchanged (3) | POS Return 77; transaction 16590 |
| product 4014 / location 6 | not a candidate | — | no replacement lineage; excluded at discovery |

Both corrected buckets land on the sellable serial count (47 and 3).

**`--apply` has not been run against production and must stay untouched until
browser verification and a final reviewed dry-run are complete.**

Pre-existing failures (present on HEAD before this change, and reduced by it —
not caused by it; left unchanged as out of scope):

| Suite | HEAD baseline | With this change |
| --- | --- | --- |
| `POSReturnAtomicLifecycleTest` | 19 failed | 1 failed |
| `POSReturnBundleLifecycleExecutionTest` | 6 failed | 1 failed |
| `POSReturnDraftResolutionVerificationTest` | 6 failed | 4 failed |
| `POSReturnBundleCarrierRowCashReturnCompletenessTest` | 1 failed | 1 failed |

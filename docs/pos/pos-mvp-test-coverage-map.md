# POS MVP Test Coverage Map

This document maps the POS MVP test scenarios (defined in `pos-mvp-test-matrix.md`) to the automated test files that cover them. All critical-path tests are annotated with the `@group pos-critical-path` PHPUnit annotation.

## Running the Critical Path Suite

To run the entire critical-path test suite:

```bash
php artisan test --testsuite=Pos --group=pos-critical-path
```

## Coverage Mapping (P0 Scenarios)

| Scenario ID | Description | Test Class File (`Modules/Pos/Tests/Feature/` or `Unit/`) |
|---|---|---|
| **POS-TM-001** | Session feature flag disabled | `Feature/POSRouteFeatureFlagTest.php`, `Feature/POSShellSessionGuardTest.php` |
| **POS-TM-002** | Start session with opening float | `Feature/POSOpeningFloatCaptureTest.php`, `Feature/POSSessionLifecycleTest.php`, `Feature/POSTerminalRegistryPolicyTest.php`, `Unit/PosTerminalRuntimeResolverTest.php` |
| **POS-TM-003** | Auto-assignment to terminal + permission | `Feature/POSSessionLifecycleTest.php`, `Feature/POSPermissionRoleMappingTest.php` |
| **POS-TM-004** | Session close validation rules | `Feature/POSSessionLifecycleTest.php`, `Feature/POSSessionCloseWorkflowTest.php` |
| **POS-TM-005** | Double-close rejection | `Feature/POSSessionCloseWorkflowTest.php` |
| **POS-TM-010** | Accurate expected cash computation | `Feature/POSExpectedCashCalculatorTest.php`, `Feature/POSLiveSessionMonitorTest.php` |
| **POS-TM-011** | Deduct safe drop amount | `Feature/POSSafeDropWorkflowTest.php` |
| **POS-TM-012** | Safe drop without sufficient cash | `Feature/POSSafeDropWorkflowTest.php` |
| **POS-TM-020** | Basic retail checkout | `Feature/POSCheckoutFinalizeIdempotencyTest.php`, `Feature/POSPaymentValidationRulesTest.php`, `Feature/POSProductSearchScanTest.php`, `Feature/POSWalkInCustomerSelectionTest.php` |
| **POS-TM-021** | Correct total arithmetic recorded | `Feature/POSCheckoutFinalizeIdempotencyTest.php` |
| **POS-TM-022** | Non-cash method requires reference | `Feature/POSCheckoutFinalizeIdempotencyTest.php`, `Feature/POSPaymentValidationRulesTest.php` |
| **POS-TM-023** | Correct change computation | `Feature/POSCheckoutFinalizeIdempotencyTest.php`, `Feature/POSPaymentValidationRulesTest.php` |
| **POS-TM-030** | Double finalization rejected | `Feature/POSCheckoutFinalizeIdempotencyTest.php` |
| **POS-TM-031** | Network retry recognized as safe | `Feature/POSCheckoutFinalizeIdempotencyTest.php` |
| **POS-TM-032** | Rollback on mid-posting failure | `Feature/POSCheckoutFinalizeIdempotencyTest.php` |
| **POS-TM-040** | Decrement location's available quantity | `Feature/POSStockAllocationResolverTest.php` |
| **POS-TM-041** | Fallback behavior defined | `Feature/POSStockAllocationResolverTest.php` |
| **POS-TM-042** | Borrowed qty requires confirmation | `Feature/POSStockAllocationResolverTest.php` |
| **POS-TM-043** | Split allocation across shelves | `Feature/POSStockAllocationResolverTest.php` |
| **POS-TM-044** | Prevent negative stock logic | `Feature/POSStockAllocationResolverTest.php` |
| **POS-TM-050** | Tax matches branch configuration | `Feature/POSTaxBySourceSnapshotTest.php` |
| **POS-TM-051** | Mixed tax outcomes in split | `Feature/POSTaxBySourceSnapshotTest.php` |
| **POS-TM-052** | Historical stability after config change | `Feature/POSTaxBySourceSnapshotTest.php` |
| **POS-TM-060** | Require assignment before finalize | `Feature/POSSerialValidationCheckoutTest.php` |
| **POS-TM-061** | Same serial multple lines rejection | `Feature/POSSerialValidationCheckoutTest.php` |
| **POS-TM-062** | Unavailable serial rejection | `Feature/POSSerialValidationCheckoutTest.php` |
| **POS-TM-070** | Validate manual discount rules | `Feature/POSCartTotalsDisplayTest.php`, `Unit/PosCartTotalsCalculatorTest.php` |
| **POS-TM-071** | Override price logged with approver | `Feature/POSCartTotalsDisplayTest.php` |
| **POS-TM-072** | Unauthorized override rejected | `Feature/POSCartTotalsDisplayTest.php` |
| **POS-TM-080** | Cross-reference IDs stored | `Feature/POSCriticalPathCrossReferenceTest.php` |
| **POS-TM-081** | Accurate cash totals reported | `Feature/POSExpectedCashCalculatorTest.php`, `Feature/POSCheckoutFinalizeIdempotencyTest.php` |
| **POS-TM-082** | Flag unresolved discrepancies | `Feature/POSReconciliationViewTest.php` |
| **POS-TM-090** | PDF layout matching specs | `Feature/POSReceiptGenerationTest.php` |
| **POS-TM-091** | Non-fatal print failure | `Feature/POSReceiptGenerationTest.php` |
| **POS-TM-092** | Safe drop/Sale opens drawer | `Feature/POSCashDrawerHookTest.php` |

## Notes

- The test suite is executed automatically in CI via `run_command` in automated pipelines.
- All non-UI critical functionalities needed for POS-MVP release are verified by this mapped suite.

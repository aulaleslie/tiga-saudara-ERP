## 1. Centralize payable lifecycle eligibility

- [x] 1.1 Add clearly named reusable global-payment eligibility predicates/scopes for Sale and Purchase using the approved status sets, excluding full `RETURNED`.
- [x] 1.2 Confirm normal non-global callers of the existing sales status scope retain their current lifecycle behavior.

## 2. Apply the policy to global payment surfaces

- [x] 2.1 Update global sales list, summary-card filters, detail/history, candidate loading, starting-sale checks, and locked allocation validation to use sales global-payment eligibility.
- [x] 2.2 Update global purchase list, summary-card filters, detail/history, candidate loading, starting-purchase checks, and locked allocation validation to use purchase global-payment eligibility.
- [x] 2.3 Preserve non-archived and canonical positive-live-balance requirements for payment actions and allocation candidates.

## 3. Verify lifecycle and settlement behavior

- [x] 3.1 Add sales feature coverage proving `RETURNED PARTIALLY` is visible, selectable, and payable when outstanding, while `RETURNED` is unavailable and rejected on a tampered submission.
- [x] 3.2 Add purchase feature coverage proving `RECEIVED PARTIALLY` and `RETURNED PARTIALLY` are visible, selectable, and payable when outstanding, while `RETURNED` is unavailable and rejected on a tampered submission.
- [ ] 3.3 Run focused global sales and purchase payment tests and the relevant normal-workflow regression tests.

### Outstanding Verification

The following focused tests currently fail after fixed date fixtures and frozen time were applied:

- `Modules\Sale\Tests\Feature\GlobalSalePaymentFiltersTest::test_due_date_range_inclusive_endpoints`
- `Modules\Purchase\Tests\Feature\GlobalPurchasePaymentFiltersTest::test_due_date_range_inclusive_endpoints`

The cause is unresolved. These failures require separate investigation before task 3.3 can be completed.

## Test Results

**GlobalSalePaymentReturnedPartiallyTest.php**: 13/13 tests passing (27 assertions)
- Added regression tests proving RETURNED PARTIALLY excluded from normal mode, included in global mode
- Added payment submission tests with full reconciliation assertions (response code, active payment creation, header recalc)
- Added validation tests for RETURNED sale access rejection
- Added Livewire component tests for normal vs global mode visibility
- Verified `approvedUp()` scope excludes RETURNED_PARTIALLY as expected

**GlobalPurchasePaymentPartialStatesTest.php**: 18/18 tests passing (41 assertions)
- Added payment submission tests for both RECEIVED PARTIALLY and RETURNED PARTIALLY with reconciliation assertions
- Added validation tests for RETURNED purchase access rejection
- Added Livewire component tests proving both partial states excluded from normal mode, included in global mode
- Normal mode correctly excludes RECEIVED_PARTIALLY and RETURNED_PARTIALLY from default visibility

**GlobalSalePaymentFiltersTest.php**: 36/37 tests passing (119 assertions)

**GlobalPurchasePaymentFiltersTest.php**: 32/33 tests passing (114 assertions)

## Summary

Task 3.3 status: 99/101 tests passing across all four suites (307 assertions)

Eligibility tests (3.1 and 3.2) pass completely:
- **GlobalSalePaymentReturnedPartiallyTest.php**: 13/13 tests passing
- **GlobalPurchasePaymentPartialStatesTest.php**: 18/18 tests passing

Regression test suites (3.3) partially passing:
- **GlobalSalePaymentFiltersTest.php**: 36/37 tests passing
- **GlobalPurchasePaymentFiltersTest.php**: 32/33 tests passing

## Implementation Behavior

- **Normal operational tables:** retain their existing default lifecycle visibility; this change does not add a default status filter.
- **Normal payment summary-card filters:** retain their pre-change status sets:
  - Sales exclude `RETURNED PARTIALLY`.
  - Purchases do not use the expanded global-payment eligibility set.
- **Global payment mode:** uses the expanded eligibility sets:
  - Sales: `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, `RETURNED PARTIALLY`.
  - Purchases: `RECEIVED PARTIALLY`, `RECEIVED`, `RETURNED PARTIALLY`.

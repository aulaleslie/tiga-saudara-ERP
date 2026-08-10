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
- [x] 3.3 Run focused global sales and purchase payment tests and the relevant normal-workflow regression tests.

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

**GlobalSalePaymentFiltersTest.php**: 37/37 tests passing (120 assertions)
- Verified inclusive due-date range filtering with fixed dates and frozen application time

**GlobalPurchasePaymentFiltersTest.php**: 33/33 tests passing (115 assertions)
- Verified inclusive due-date range filtering with fixed dates and frozen application time

## Summary

Task 3.3 complete: 101/101 tests passing across all four suites (308 assertions)
- GlobalSalePaymentReturnedPartiallyTest.php: 13/13 passing
- GlobalPurchasePaymentPartialStatesTest.php: 18/18 passing
- GlobalSalePaymentFiltersTest.php: 37/37 passing
- GlobalPurchasePaymentFiltersTest.php: 33/33 passing

## Implementation Behavior

- Normal operational tables retain their existing default lifecycle visibility.
- Normal payment summary-card filters retain their pre-change status sets.
- Global payment mode uses the expanded global-payment eligibility sets.

### Due-Date Range Filtering

The global payment filter applies `due_date <= Carbon::parse($this->dueDateTo)->endOfDay()` to ensure inclusive upper boundaries. SQLite's datetime-string comparison treats `YYYY-MM-DD` as `00:00:00`, which would exclude documents dated exactly on the end date when compared with `<=`. Converting the end date to end-of-day (`23:59:59.999`) ensures records dated on the end date are included across test environments and storage backends that use datetime representations.

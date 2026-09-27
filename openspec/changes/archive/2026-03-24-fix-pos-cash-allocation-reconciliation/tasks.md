## 1. Core Implementation

- [x] 1.1 Update `PosCheckoutOwnershipPriorityAllocationService::allocate()` to calculate `checkoutGrandTotalMinor` early.
- [x] 1.2 Implement logic in `allocate()` to calculate `overpayment_amount` (total provided minus grand total).
- [x] 1.3 Implement logic to sequentially subtract `overpayment_amount` from the `amount_minor_units` of cash payments until the overpayment is fully absorbed, capping them to the exact required grand total.

## 2. Testing

- [x] 2.1 Add a test case `test_cash_overpayment_allocation_reconciles` to `POSCheckoutOwnershipPriorityAllocationTest` that simulates a 100,000 cash payment for an 80,000 cart with split groups.
- [x] 2.2 Verify that the allocation perfectly balances down to 0 and throws no validation exceptions.


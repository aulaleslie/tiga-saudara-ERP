## Why

The POS checkout system currently throws an `Allocation matrix does not reconcile: groups have unallocated balances` error when a cashier processes a cash payment with change (e.g., entering 100,000 for an 80,000 cart). 

This happens because the cash overpayment (the customer's change) is being fed directly into the allocation matrix. The `PosCheckoutOwnershipPriorityAllocationService` attempts to allocate the entire tendered amount proportionally, driving split group balances into the negative and failing the final reconciliation check. We need to fix this to allow normal cash transactions that require change to complete successfully.

## What Changes

- Modify `PosCheckoutOwnershipPriorityAllocationService::allocate()` to cap the sum of cash payments.
- Ensure only the *applied* payment amount is distributed to the split groups, ignoring the *tendered* overflow.
- Ensure that the reconciliation matrix logic strictly allocates up to the `checkoutGrandTotalMinor` and leaves `groupBalances` exactly at `0`.
- Add test cases covering overpaid cash transactions to `POSCheckoutOwnershipPriorityAllocationTest`.

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- None (This is a bug fix for existing payment behavior).

## Impact

- `Modules\Pos\Services\PosCheckoutOwnershipPriorityAllocationService`
- `Modules\Pos\Tests\Feature\POSCheckoutOwnershipPriorityAllocationTest`

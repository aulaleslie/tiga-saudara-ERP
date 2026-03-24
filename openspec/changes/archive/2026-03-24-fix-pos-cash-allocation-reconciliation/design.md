## Context

The POS checkout flow supports multi-payment and split groups (e.g., terminal-owned vs. non-terminal-owned products). When a customer pays with cash, they often provide an amount larger than the grand total. The `PosSellController` and `FinalizePosCheckoutService` correctly handle this by calculating the change to be returned.

However, the `PosCheckoutOwnershipPriorityAllocationService`, which determines exactly how much of each payment applies to each split group, receives the raw *tendered* payment amounts. It attempts to allocate the full tendered amount, including the change, across the groups. This causes the remaining balances of the groups to go below zero (negative), which then trips the final validation check (`array_sum($groupBalances) !== 0`), throwing an `Allocation matrix does not reconcile: groups have unallocated balances` exception.

## Goals / Non-Goals

**Goals:**
- Allow the POS checkout allocation matrix to gracefully handle cash payments that exceed the required grand total.
- Maintain the strict reconciliation validation (`array_sum($groupBalances) === 0`) to ensure data integrity.

**Non-Goals:**
- Altering the upstream tracking of cash tendered vs. change returned (this already works correctly in `FinalizePosCheckoutService`).
- Changing the behavior around non-cash payments (which are strictly forbidden from overpaying by existing validation rules).

## Decisions

**Decision 1: Cap Cash Payment Amounts Inside the Allocation Service (Chosen)**
Instead of relying on the upstream `SplitPosCheckoutPostingAdapter` to sanitize the payment array, we will modify `PosCheckoutOwnershipPriorityAllocationService::allocate()` to cap the total payment amounts strictly to the needed grand total.

*Rationale:* The allocation matrix is responsible for solving the distribution of funds to groups. By making it intelligent enough to recognize and trim cash overpayments (change), the service is natively robust and guarantees its own mathematical reconciliation.

*Implementation Mechanism:* 
1. Calculate the required total (`checkoutGrandTotalMinor` = `sum(group['grand_total_minor'])`).
2. Calculate the provided total from all payments.
3. If provided > required, calculate the `overpayment` amount (which must logically belong to cash payments, given upstream validation).
4. Iterate through the reordered cash payments and subtract the `overpayment` from their `amount_minor_units` until the overpayment is fully absorbed.
5. Proceed with the normal ownership priority allocation logic.

## Risks / Trade-offs

- **Risk:** Multi-payment arrays where a *non-cash* payment somehow exceeds the grand total.
  *Mitigation:* Upstream validation already strictly blocks non-cash payments from exceeding the remainder. We can add a sanity check: if the overpayment cannot be fully absorbed by cash payments, throw an exception because the input is mathematically invalid.

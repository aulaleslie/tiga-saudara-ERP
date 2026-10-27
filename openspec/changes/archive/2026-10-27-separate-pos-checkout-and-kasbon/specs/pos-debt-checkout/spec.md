## MODIFIED Requirements

### Requirement: POS SHALL offer a finish-as-debt checkout path
The POS SHALL provide a dedicated "Kasbon" action alongside "Bayar Lunas" and an authorized flow switch inside the payment modal. Choosing it SHALL complete the transaction and post a `Sale` with an outstanding balance rather than requiring full payment.

#### Scenario: Cashier chooses finish as debt
- **WHEN** a cashier opens the checkout modal for a cart with grand total greater than zero and selects "Kasbon"
- **THEN** the system MUST present the debt sub-flow (customer requirement, payment term selection, optional down payment) instead of requiring payment equal to the grand total

#### Scenario: Full-payment path unaffected
- **WHEN** a cashier completes checkout via the normal full-payment path
- **THEN** the resulting sale MUST remain fully paid with `payment_status = 'Paid'` and `due_amount = 0`, unchanged by this capability

### Requirement: Debt checkout SHALL allow an optional down payment below the grand total
The debt path SHALL allow zero, one, or multiple down-payment entries through the payment picker, where `0 ≤ combined_down_payment < grand_total`. Cashiers SHALL explicitly choose when to finish Kasbon so they can add further down payments. The remainder SHALL become the sale's outstanding balance. A payment equal to or exceeding the grand total MUST NOT be treated as debt.

#### Scenario: Zero down payment
- **WHEN** a cashier completes a debt checkout with no down payment
- **THEN** the posted sale MUST set `paid_amount = 0`, `due_amount = grand_total`, and `payment_status = 'Unpaid'`, and MUST NOT create a `SalePayment`

#### Scenario: Partial down payment
- **WHEN** a cashier completes a debt checkout with a down payment greater than zero and less than the grand total
- **THEN** the posted sale MUST set `paid_amount` to the down payment, `due_amount = grand_total − down_payment`, and `payment_status = 'Partial'`, and MUST create settlement records representing the down-payment entries

#### Scenario: Full payment rejected on debt path
- **WHEN** a cashier attempts a debt checkout with a down payment equal to or greater than the grand total
- **THEN** the system MUST reject the debt checkout (the transaction is a normal full payment, not debt)

#### Scenario: Multiple down payments
- **WHEN** a cashier adds Rp10,000,000 cash and Rp20,000,000 transfer toward a Rp52,985,000 Kasbon
- **THEN** both entries remain visible and the combined down payment is Rp30,000,000 with Rp22,985,000 outstanding
- **AND** the cashier can explicitly proceed to final confirmation

#### Scenario: Combined payment covers total
- **WHEN** combined down payments reach or exceed the total
- **THEN** Kasbon completion is blocked with guidance to switch to Bayar Lunas
- **AND** the flow is not silently converted

## ADDED Requirements

### Requirement: Proportional allocation SHALL reconcile large payment amounts
Proportional payment allocation SHALL support valid large checkout amounts without intermediate integer overflow. Allocations SHALL sum exactly to the paid amount in minor units, preserve deterministic largest-remainder rounding, and keep each sale's outstanding balance consistent with its allocated payment.

#### Scenario: Reported production amounts
- **WHEN** a Rp52,985,000 split checkout with groups totaling Rp39,385,000 and Rp13,600,000 receives a Rp30,000,000 Kasbon down payment
- **THEN** posting completes without numeric overflow
- **AND** allocated payments sum to Rp30,000,000 and outstanding balances sum to Rp22,985,000

#### Scenario: Rounding and zero payment
- **WHEN** proportional shares require rounding or the paid amount is zero
- **THEN** results remain deterministic and sum exactly to the paid amount without negative allocations

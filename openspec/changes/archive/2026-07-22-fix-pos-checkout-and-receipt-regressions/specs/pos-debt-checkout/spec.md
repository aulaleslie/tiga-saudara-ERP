## Purpose

Debt checkout through the POS staged-payment modal SHALL support zero and partial down payments that remain stable through authorization and finalization, preserving the customer, payment term, debt mode, and payment chain context until posting.

## Requirements

## MODIFIED Requirements

### Requirement: POS SHALL offer a finish-as-debt checkout path
The POS staged-payment modal SHALL provide a "Selesaikan sebagai Utang" option alongside the full-payment option. Choosing it SHALL establish a persistent debt checkout mode that posts a `Sale` with an outstanding balance instead of requiring payments equal to the grand total.

#### Scenario: Cashier chooses finish as debt
- **WHEN** a cashier opens checkout for a cart with grand total greater than zero, a named customer, and selects "Selesaikan sebagai Utang"
- **THEN** the system presents payment-term selection and an optional down-payment input instead of requiring full settlement

#### Scenario: Full-payment path unaffected
- **WHEN** a cashier completes checkout through the normal full-payment path
- **THEN** the resulting sale MUST remain fully paid with `payment_status = 'Paid'` and `due_amount = 0`

#### Scenario: Debt mode survives a partial payment stage
- **WHEN** a cashier commits an allowed partial down payment while debt mode is active
- **THEN** the system retains debt mode and the selected payment term while preparing final transaction confirmation

### Requirement: Debt checkout SHALL allow an optional down payment below the grand total
The debt path SHALL accept an optional down payment where `0 ≤ down payment < grand total`. Zero down payment MUST be finalizable without selecting a payment method, and a positive partial down payment SHALL use the existing payment picker without applying the normal full-settlement minimum. A payment equal to or exceeding the grand total MUST NOT be treated as debt.

#### Scenario: Zero down payment
- **WHEN** the cashier selects debt mode, a named customer, and a payment term while leaving the down payment at zero
- **THEN** the UI SHALL allow final confirmation and the posted sale MUST have `paid_amount = 0`, `due_amount = grand total`, and `payment_status = 'Unpaid'` without creating a `SalePayment`

#### Scenario: Partial cash down payment
- **WHEN** the cashier enters a cash down payment greater than zero and less than the remaining grand total in debt mode
- **THEN** the UI SHALL accept the amount even though it does not fully settle the balance, and the posted sale MUST have `payment_status = 'Partial'`

#### Scenario: Partial non-cash down payment
- **WHEN** the cashier enters an allowed non-cash down payment below the grand total with all method-specific reference requirements satisfied
- **THEN** the UI SHALL accept the amount and preserve it for debt finalization

#### Scenario: Full payment rejected on debt path
- **WHEN** a cashier attempts a debt checkout with a down payment equal to or greater than the grand total
- **THEN** the system MUST reject the debt checkout and direct the cashier to use normal full payment

## ADDED Requirements

### Requirement: Debt checkout context SHALL remain stable through finalization
The system SHALL retain the selected customer, debt mode, payment term, payment chain, and computed outstanding balance from debt selection through final confirmation, supervisor authorization when required, and checkout finalization.

#### Scenario: Supervisor approval is requested
- **WHEN** a cashier without direct debt permission requests approval for a valid debt checkout
- **THEN** canceling, polling, or completing the approval flow MUST NOT clear or change the debt checkout context

#### Scenario: Approved debt checkout is retried
- **WHEN** an approval token becomes available and the cashier proceeds with the same debt checkout
- **THEN** the finalization request MUST contain the original debt flag, payment term, payment chain, customer context, and approval token


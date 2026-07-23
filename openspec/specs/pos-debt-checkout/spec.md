# pos-debt-checkout Specification

## Purpose
TBD - created by archiving change add-pos-debt-checkout. Update Purpose after archive.
## Requirements
### Requirement: POS SHALL offer a finish-as-debt checkout path
The POS payment/checkout modal SHALL provide a "Selesaikan sebagai Utang" (finish as debt) option alongside the full-payment option. Choosing it SHALL complete the transaction and post a `Sale` with an outstanding balance rather than requiring full payment.

#### Scenario: Cashier chooses finish as debt
- **WHEN** a cashier opens the checkout modal for a cart with grand total greater than zero and selects "Selesaikan sebagai Utang"
- **THEN** the system MUST present the debt sub-flow (customer requirement, payment term selection, optional down payment) instead of requiring payment equal to the grand total

#### Scenario: Full-payment path unaffected
- **WHEN** a cashier completes checkout via the normal full-payment path
- **THEN** the resulting sale MUST remain fully paid with `payment_status = 'Paid'` and `due_amount = 0`, unchanged by this capability

### Requirement: Debt checkout SHALL require a named customer
The debt path SHALL be blocked unless the cart resolves to a named customer. A walk-in/guest or unresolved customer MUST NOT be allowed to complete as debt.

#### Scenario: Debt blocked for guest customer
- **WHEN** a cashier attempts finish-as-debt while the cart has no resolved customer
- **THEN** the system MUST reject the debt checkout and MUST prompt the cashier to select a customer

#### Scenario: Debt allowed for named customer
- **WHEN** a cashier attempts finish-as-debt while the cart resolves to a named customer
- **THEN** the system MUST allow the debt sub-flow to proceed

### Requirement: Debt checkout SHALL require a payment term and compute the due date
The debt path SHALL require the cashier to select a payment term from the existing `payment_terms`. The resulting sale's `due_date` SHALL be computed as the checkout date plus the selected term's `longevity` in days, and the sale's `payment_term_id` SHALL be the selected term.

#### Scenario: Term is required
- **WHEN** a cashier submits a debt checkout without selecting a payment term
- **THEN** the system MUST reject the checkout and MUST prompt for a payment term

#### Scenario: Due date derived from term longevity
- **WHEN** a cashier selects a term with `longevity = 30` and completes a debt checkout on a given date
- **THEN** the posted sale MUST set `payment_term_id` to that term and `due_date` to the checkout date plus 30 days

### Requirement: Debt checkout SHALL allow an optional down payment below the grand total
The debt path SHALL allow an optional down payment where `0 ≤ down_payment < grand_total`, taken through the existing payment picker. The remainder SHALL become the sale's outstanding balance. A payment equal to or exceeding the grand total MUST NOT be treated as debt.

#### Scenario: Zero down payment
- **WHEN** a cashier completes a debt checkout with no down payment
- **THEN** the posted sale MUST set `paid_amount = 0`, `due_amount = grand_total`, and `payment_status = 'Unpaid'`, and MUST NOT create a `SalePayment`

#### Scenario: Partial down payment
- **WHEN** a cashier completes a debt checkout with a down payment greater than zero and less than the grand total
- **THEN** the posted sale MUST set `paid_amount` to the down payment, `due_amount = grand_total − down_payment`, and `payment_status = 'Partial'`, and MUST create a `SalePayment` for the down payment

#### Scenario: Full payment rejected on debt path
- **WHEN** a cashier attempts a debt checkout with a down payment equal to or greater than the grand total
- **THEN** the system MUST reject the debt checkout (the transaction is a normal full payment, not debt)

### Requirement: Debt sale SHALL be collectible from the Sales document
A debt checkout SHALL post a `Sale` whose outstanding balance is collected later through the existing Sales document payment flow, with no debt-collection UI in POS.

#### Scenario: Later collection recomputes status
- **WHEN** a later payment is recorded against a debt sale from the Sales document
- **THEN** the sale's `paid_amount`, `due_amount`, and `payment_status` MUST be recomputed by the existing Sales payment flow toward `Paid`

#### Scenario: Debt sale appears in receivables
- **WHEN** a debt sale is posted with an outstanding balance
- **THEN** it MUST appear in the existing outstanding-receivables ("Piutang Belum Tertagih") view without additional POS reporting

### Requirement: Debt checkout SHALL reconcile session cash and split allocation by actual paid amount
The debt path SHALL feed session cash reconciliation and split-sale allocation using the actual down-payment amount, not the grand total.

#### Scenario: Zero down payment adds no cash
- **WHEN** a debt checkout is completed with no down payment
- **THEN** the session `expected_cash_total` MUST NOT change and no cash-sale inflow event MUST be recorded

#### Scenario: Partial cash down payment across split sales
- **WHEN** a debt checkout with a partial cash down payment posts across multiple split sales
- **THEN** the down payment MUST be allocated proportionally so that each split sale's `paid_total` sums to the down payment and each split sale's `due_amount` reconciles with its grand total minus its allocated paid amount

### Requirement: Debt checkout SHALL expose existing payment terms reliably
When a named customer enables the debt checkout sub-flow, the POS SHALL load every existing `payment_terms` row available to the application and present the returned terms for selection. The POS MUST NOT treat a failed or invalid payment-term response as a successful empty result.

#### Scenario: Existing payment terms populate the debt selector
- **WHEN** an authorized cashier enables debt checkout and one or more payment terms exist
- **THEN** the POS payment-term endpoint MUST return the existing terms with their identifiers, names, and longevity values
- **AND** the staged-payment modal MUST present those terms for selection

#### Scenario: No payment terms exist
- **WHEN** an authorized cashier enables debt checkout and the payment-term endpoint succeeds with no existing terms
- **THEN** the modal MUST state that no payment terms are available
- **AND** debt checkout continuation MUST remain disabled

#### Scenario: Payment-term loading fails
- **WHEN** the payment-term request returns a non-success response, an invalid payload, or a network failure
- **THEN** the staged-payment modal MUST display an actionable payment-term loading error
- **AND** debt checkout continuation MUST remain disabled
- **AND** the cashier MUST be able to retry loading terms without reloading the entire POS page


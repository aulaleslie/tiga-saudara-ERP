## ADDED Requirements

### Requirement: checkoutFinalize SHALL accept debt fields and post an outstanding-balance sale
The `POST /pos/sell/checkout/finalize` endpoint SHALL accept debt-checkout inputs — a debt flag, the selected `payment_term_id`, an optional down payment, and an approval token when required — and SHALL post a `Sale` with an outstanding balance instead of a fully-paid sale. Authorization for the debt action SHALL be enforced server-side during finalize (direct permission, consumed approval token, or Super Admin bypass).

#### Scenario: Finalize posts an unpaid debt sale
- **WHEN** finalize is called with the debt flag set, a valid `payment_term_id`, no down payment, and valid authorization
- **THEN** the posted sale MUST have `paid_amount = 0`, `due_amount = grand_total`, `payment_status = 'Unpaid'`, the selected `payment_term_id`, and a `due_date` derived from the term longevity

#### Scenario: Finalize posts a partial debt sale
- **WHEN** finalize is called with the debt flag set, a valid `payment_term_id`, a down payment greater than zero and below the grand total, and valid authorization
- **THEN** the posted sale MUST have `paid_amount` equal to the down payment, `due_amount = grand_total − down_payment`, and `payment_status = 'Partial'`

#### Scenario: Finalize rejects unauthorized debt checkout
- **WHEN** finalize is called with the debt flag set by a user lacking direct permission and without a valid approval token
- **THEN** the system MUST reject the finalize with an approval-required error and MUST NOT post a sale

### Requirement: Debt checkout SHALL be idempotent and distinct from full-payment finalize
The finalize idempotency hash SHALL incorporate the debt flag and selected `payment_term_id` so that a debt attempt cannot replay as a full-payment checkout, and a retry with a different payment term is not replayed with a stale term.

#### Scenario: Debt attempt does not collide with full-payment attempt
- **WHEN** a full-payment finalize and a debt finalize are submitted for the same cart contents
- **THEN** they MUST produce distinct idempotency hashes and MUST NOT replay each other

#### Scenario: Term change on retry is not stale-replayed
- **WHEN** a debt finalize is retried for the same cart and down payment but with a different `payment_term_id`
- **THEN** the idempotency hash MUST differ so the new term is honored rather than replaying the prior result

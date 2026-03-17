## MODIFIED Requirements

### Requirement: Multi-stage payment flow uses cart token for session tracking
The system SHALL track payment chains using cart-scoped session keys `payment_chain_{cart_token}` instead of `payment_chain_{sale_id}`. This allows the flow to operate before Sales are created.

#### Scenario: Token-based session tracking
- **WHEN** user begins checkout with token "xyz-456"
- **THEN** all payment stages use session key `payment_chain_xyz-456` to track committed payments

#### Scenario: Remainder recalculation with token
- **WHEN** second stage payment is submitted with same token
- **THEN** backend reads `payment_chain_xyz-456`, calculates `remainder = grand_total - total_committed`, returns updated remainder

### Requirement: Staged payment flow continues after browser reload
If a browser reload occurs mid-transaction, the system SHALL recover the payment chain from session and auto-open the modal at the correct stage with all previous payments visible.

#### Scenario: Reload after first stage
- **WHEN** user commits BRI 500k, then refreshes page
- **THEN** page load detects `staged_payment_token` in snapshot, calls GET payment-chain, finds chain, modal opens showing 1 committed payment and remainder 500k

#### Scenario: Modal displays full payment history
- **WHEN** modal reopens with recovered chain (2 payments committed)
- **THEN** payment chain section shows "✓ BRI 500k" and "✓ CASH 500k", remainder shows 0

### Requirement: User can abandon incomplete payment and return to cart
If no payments have been committed yet (payment chain is empty), the user can close the modal without creating any records. Closing SHALL clear the session token and return to normal cart state.

#### Scenario: Close on empty payment chain
- **WHEN** user opens staged modal but submits no payments, then clicks close button
- **THEN** session `payment_chain_{cart_token}` is cleared, modal closes, user sees cart

#### Scenario: Cannot close after payment committed
- **WHEN** user has committed 1+ payment and tries to close modal
- **THEN** close button is disabled (hidden), user must either continue payments or refresh (reload recovery preserves chain)

### Requirement: EDC reference validation happens at stage entry
Non-cash payment methods that require EDC reference SHALL have the reference validated at the time of stage submission. Format SHALL be alphanumeric, 1-20 characters.

#### Scenario: Valid EDC reference
- **WHEN** user submits BRI payment with reference "ABC12345"
- **THEN** stage payment accepts it, stores in SalePayment.edc_reference

#### Scenario: Invalid EDC reference format
- **WHEN** user submits BRI payment with reference "invalid@123" (contains @)
- **THEN** stage payment returns 422 validation error

### Requirement: Remainder is recalculated after each stage
After each payment stage is committed, the system SHALL recalculate remainder as `grand_total - sum(all_committed_amounts)` and return it to the frontend.

#### Scenario: Remainder after first stage
- **WHEN** user pays 500k of 1.5M grand total
- **THEN** remainder returned as 1000000

#### Scenario: Overpayment calculation
- **WHEN** grand total is 1M and user pays 1.2M in final stage
- **THEN** system calculates overpayment: remainder = -200000, absolute value is change = 200000

### Requirement: Idempotency key prevents duplicate stage submissions
Each stage payment submission SHALL include an idempotency_key. If a key is resubmitted, the backend SHALL return the previous response without creating a duplicate SalePayment record.

#### Scenario: First submission succeeds
- **WHEN** user submits stage with idempotency_key "key-1" and amount 500k
- **THEN** SalePayment record created, response returns remainder

#### Scenario: Duplicate submission returns cached response
- **WHEN** user resubmits same stage with idempotency_key "key-1"
- **THEN** no new SalePayment is created, same response is returned

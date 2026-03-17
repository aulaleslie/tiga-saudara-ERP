# pos-multi-stage-payment-flow Specification

## Purpose
TBD - created by archiving change enable-pos-multi-stage-payment-flow. Update Purpose after archive.
## Requirements
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

### Requirement: Payment Modal Opens with Remainder Calculation
The payment modal SHALL open when user clicks "Pilih Pembayaran" button, displaying the current remainder amount (grand_total minus previously committed payments) and a method selector for selecting a single payment method.

#### Scenario: Modal opens on initial checkout (no prior payments)
- **WHEN** user clicks "Pilih Pembayaran" button with cart total of 2,950,000 IDR
- **THEN** modal opens showing remainder of 2,950,000 IDR and payment method selector

#### Scenario: Modal opens for subsequent stage (after first payment committed)
- **WHEN** user has already committed BRI payment of 1,000,000 IDR and selects next payment method
- **THEN** modal opens showing remainder of 1,950,000 IDR (2,950,000 - 1,000,000)

### Requirement: User Selects Single Payment Method and Amount
The payment modal SHALL allow user to select one payment method from a searchable list and enter an amount for that stage. CASH payments proceed directly to commit; non-cash payments display an additional EDC reference input field.

#### Scenario: User selects CASH and enters amount
- **WHEN** user selects "CASH" from method dropdown and enters "1000000" in amount field
- **THEN** the amount is displayed in the current stage form and [Proceed] button is enabled

#### Scenario: User selects non-cash method (BRI) and enters amount
- **WHEN** user selects "BRI" from method dropdown and enters "1000000" in amount field
- **THEN** an additional field appears: "Referensi EDC (digit terakhir)" for manual receipt reference entry

### Requirement: Stage Payment Submission and Remainder Recalculation
When user clicks [Proceed], the payment stage SHALL be submitted to the backend via `POST /pos/sell/checkout/stage-payment`. Upon successful response, the remainder SHALL be recalculated. If remainder > 0, modal resets for next stage; if remainder = 0, final flow is triggered (receipt print + gratitude dialog).

#### Scenario: User submits CASH payment, remainder remains
- **WHEN** user submits CASH payment of 1,000,000 IDR (remainder was 2,950,000)
- **THEN** backend commits payment, returns remainder of 1,950,000, and modal resets showing new remainder with empty method selector

#### Scenario: User submits final payment, transaction complete
- **WHEN** user submits payment of 950,000 IDR when remainder was 950,000
- **THEN** backend commits payment, returns remainder of 0, modal triggers receipt print flow and gratitude dialog

### Requirement: Payment Chain Visibility
The payment modal SHALL display a list of previously committed payments in the current transaction, showing method name, amount, and status (✓). This list is visible above the current stage section.

#### Scenario: User sees payment chain after first commit
- **WHEN** user has committed BRI 1,000,000 IDR
- **THEN** modal displays: "✓ BRI 1,000,000" in a payment chain list above the current stage selector

#### Scenario: Payment chain grows with each stage
- **WHEN** user has committed BRI 1,000,000 and BNI 1,000,000
- **THEN** modal displays both previous payments: "✓ BRI 1,000,000" and "✓ BNI 1,000,000"

### Requirement: Modal Lock During Processing
While a stage payment is in-flight (awaiting server response), the payment modal SHALL disable all input fields, hide or disable the close button, show a loading spinner, and display message "Processing payment... do not close or reload."

#### Scenario: User cannot interact during payment processing
- **WHEN** user submits payment and request is in-flight
- **THEN** amount input field is disabled, method selector is disabled, [Proceed] button shows loading state, close button is hidden

#### Scenario: Modal unlocks after successful response
- **WHEN** server responds successfully to payment submission
- **THEN** spinner disappears, loading message clears, inputs re-enable (for next stage or modal close)

### Requirement: Single Payment Convenience Path
For transactions with a single payment method covering the entire amount, the user SHALL have a straightforward path: select method, confirm amount matches remainder, click [Proceed] → payment commits → receipt prints → done. No intermediate screens or confirmations.

#### Scenario: User pays entire transaction with one CASH payment
- **WHEN** user selects CASH, enters 2,950,000 IDR (matching remainder), and clicks [Proceed]
- **THEN** payment commits immediately, remainder becomes 0, receipt print is triggered

### Requirement: Graceful Handling of Payment Amount Less Than Remainder
If user enters a payment amount less than the remainder, the modal SHALL accept it, commit the stage, and loop back to select the next payment method for the new remainder.

#### Scenario: User partially pays with first method
- **WHEN** remainder is 2,950,000 and user enters 1,000,000 for BRI payment and clicks [Proceed]
- **THEN** payment commits, remainder becomes 1,950,000, modal resets for next payment stage

### Requirement: Graceful Handling of Overpayment
If user enters a payment amount greater than the remainder, the modal SHALL accept it, commit the stage, and trigger final flow with change amount calculated and displayed.

#### Scenario: User overpays in final stage
- **WHEN** remainder is 950,000 and user enters 1,000,000 for CASH payment
- **THEN** payment commits, remainder becomes -50,000 (change of 50,000), gratitude dialog displays "Kembalian: Rp50,000"


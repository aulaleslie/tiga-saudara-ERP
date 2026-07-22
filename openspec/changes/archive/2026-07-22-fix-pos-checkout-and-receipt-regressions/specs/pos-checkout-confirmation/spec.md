## Purpose

The system SHALL display distinct confirmation phases for checkout transactions: confirmation of individual staged payments and a final transaction confirmation that precedes the irreversible finalization request. Payment-stage confirmation intercepts payment submission and details the entered amount and remaining balance. Final transaction confirmation is displayed for every path to checkout finalization and requires explicit cashier confirmation before the irreversible checkout-finalize request is submitted.

## Requirements

## MODIFIED Requirements

### Requirement: Staged Checkout Confirmation Modal
The system SHALL distinguish confirmation of an individual staged payment from confirmation of the final transaction. An individual payment that does not complete checkout SHALL retain the existing payment-stage confirmation, while every transition to the irreversible checkout-finalize request MUST display a final transaction confirmation and require an explicit cashier action.

#### Scenario: Payment equals remaining balance
- **WHEN** the cashier submits a payment amount that exactly matches the remaining balance
- **THEN** the system displays a final transaction confirmation indicating that payment is exact and does not finalize checkout until the cashier explicitly proceeds

#### Scenario: Payment is greater than remaining balance
- **WHEN** the cashier submits a cash payment amount that exceeds the remaining balance
- **THEN** the final transaction confirmation displays the calculated change and awaits explicit confirmation

#### Scenario: Payment is less than remaining balance
- **WHEN** the cashier submits a payment amount that is less than the remaining balance in a non-debt checkout
- **THEN** the payment-stage confirmation displays the resulting remaining balance and does not represent the transaction as ready for finalization

#### Scenario: Finalization after previously staged payments
- **WHEN** the payment chain already has no remaining balance and the cashier requests completion
- **THEN** the system displays the final transaction confirmation before calling the checkout-finalize endpoint

#### Scenario: Finalization with zero debt down payment
- **WHEN** the cashier has selected a valid zero-down-payment debt checkout and requests completion
- **THEN** the system displays the final transaction confirmation with the outstanding amount before calling the checkout-finalize endpoint

#### Scenario: Canceling payment-stage confirmation
- **WHEN** the cashier cancels confirmation of an individual staged payment
- **THEN** the payment is not submitted and the staged checkout input retains the entered payment details

#### Scenario: Canceling final transaction confirmation
- **WHEN** the cashier cancels the final transaction confirmation
- **THEN** checkout is not finalized and the cart, payment chain, and debt context remain available for review

#### Scenario: Proceeding with final transaction confirmation
- **WHEN** the cashier explicitly proceeds from the final transaction confirmation
- **THEN** the system dismisses the confirmation and submits exactly one idempotent checkout-finalize request

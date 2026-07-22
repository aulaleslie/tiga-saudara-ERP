# pos-checkout-confirmation Specification

## Purpose

The system SHALL display distinct confirmation phases for checkout transactions: confirmation of individual staged payments and a final transaction confirmation that precedes the irreversible finalization request. Payment-stage confirmation intercepts payment submission and details the entered amount and remaining balance. Final transaction confirmation is displayed for every path to checkout finalization and requires explicit cashier confirmation before the irreversible checkout-finalize request is submitted.

## Requirements

### Requirement: Staged Checkout Confirmation Modal
The system SHALL intercept the submission of a payment in the staged checkout flow and display a confirmation modal detailing the entered payment amount and remaining balance, requiring explicit confirmation to proceed.

#### Scenario: Payment equals remaining balance
- **WHEN** the cashier submits a payment amount that exactly matches the remaining balance
- **THEN** the confirmation modal displays a neutral or success message indicating the payment is exact ("Pembayaran Pas") and awaits confirmation.

#### Scenario: Payment is greater than remaining balance
- **WHEN** the cashier submits a payment amount that exceeds the remaining balance
- **THEN** the confirmation modal displays an info or warning alert indicating the calculated change amount ("Kembalian: Rp X") and awaits confirmation.

#### Scenario: Payment is less than remaining balance
- **WHEN** the cashier submits a payment amount that is less than the remaining balance
- **THEN** the confirmation modal displays a warning or danger alert indicating the payment is insufficient ("Pembayaran Kurang: Rp Y") and awaits confirmation.

#### Scenario: Canceling the confirmation
- **WHEN** the cashier clicks the cancel button ("Batal") in the confirmation modal
- **THEN** the modal is dismissed, the transaction is not submitted to the backend, and the cashier returns to the staged checkout input form with their previous inputs intact.

#### Scenario: Proceeding with the confirmation
- **WHEN** the cashier clicks the proceed button ("Lanjutkan") in the confirmation modal
- **THEN** the confirmation modal is dismissed and the payment is submitted to the backend for processing.

### Requirement: Final Transaction Confirmation
Every path to checkout finalization SHALL display a distinct final transaction confirmation that requires explicit cashier approval, separate from approval of individual staged payments.

#### Scenario: Final confirmation for exact payment
- **WHEN** the system reaches a state where the payment chain has no remaining balance
- **THEN** the final transaction confirmation displays the transaction total, paid amount, and awaits explicit confirmation before submitting the finalize request

#### Scenario: Final confirmation for zero debt down payment
- **WHEN** the cashier selects debt mode with no down payment
- **THEN** the final transaction confirmation displays the customer, term, paid amount (zero), and outstanding amount before submitting finalization

#### Scenario: Final confirmation cancellation preserves state
- **WHEN** the cashier cancels from the final transaction confirmation
- **THEN** the cart, payment chain, and all checkout context remain intact for review without any state reset

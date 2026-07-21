## ADDED Requirements

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

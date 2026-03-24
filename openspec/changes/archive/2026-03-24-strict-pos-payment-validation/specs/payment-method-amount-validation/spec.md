## ADDED Requirements

### Requirement: Backend enforces cash underpayment rejection
The backend `stagePayment()` endpoint SHALL reject staged cash payments where the amount is less than the current remainder, returning a 422 response with code `CASH_UNDERPAYMENT`.

#### Scenario: Cash stage payment below remainder is rejected by backend
- **WHEN** a POST request is made to `/pos/sell/checkout/stage-payment` with a cash payment method and amount 30,000 while remainder is 65,000
- **THEN** the API SHALL return HTTP 422 with `code: "CASH_UNDERPAYMENT"` and a descriptive message

#### Scenario: Cash stage payment equal to remainder succeeds
- **WHEN** a POST request is made to `/pos/sell/checkout/stage-payment` with a cash payment method and amount 65,000 while remainder is 65,000
- **THEN** the API SHALL return HTTP 201 with remainder 0

#### Scenario: Cash stage payment above remainder succeeds with negative remainder
- **WHEN** a POST request is made to `/pos/sell/checkout/stage-payment` with a cash payment method and amount 70,000 while remainder is 65,000
- **THEN** the API SHALL return HTTP 201 with remainder -5,000 (indicating change of 5,000)

### Requirement: Staged payment modal shows minimum/maximum amount hint
The staged payment modal SHALL display a contextual hint below the amount input indicating the payment constraint for the selected method.

#### Scenario: Cash method selected shows minimum hint
- **WHEN** cashier selects a cash payment method and the current remainder is 35,000
- **THEN** the modal SHALL display a hint "Minimal: Rp 35.000" below the amount input

#### Scenario: Non-cash method selected shows maximum hint
- **WHEN** cashier selects a non-cash payment method and the current remainder is 35,000
- **THEN** the modal SHALL display a hint "Maksimal: Rp 35.000" below the amount input

#### Scenario: No method selected hides the hint
- **WHEN** no payment method is selected
- **THEN** the hint element SHALL be hidden

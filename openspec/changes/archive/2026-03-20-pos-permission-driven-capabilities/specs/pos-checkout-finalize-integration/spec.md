## ADDED Requirements

### Requirement: Checkout Finalization MUST Require Explicit Checkout Permission
The checkout finalization endpoint SHALL only complete POS checkout for users who have `pos.checkout.payment`.

#### Scenario: Finalize request without checkout permission is rejected
- **WHEN** a user without `pos.checkout.payment` submits `POST /pos/sell/checkout/finalize`
- **THEN** the system MUST return a forbidden response
- **AND** the system MUST NOT post a checkout sale
- **AND** the system MUST NOT clear the payment chain session state

#### Scenario: Finalize request with checkout permission succeeds normally
- **WHEN** a user with `pos.checkout.payment` submits `POST /pos/sell/checkout/finalize` with valid cart token and payment state
- **THEN** the existing checkout finalization behavior MUST proceed unchanged

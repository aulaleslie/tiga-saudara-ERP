## ADDED Requirements

### Requirement: Multi-stage Payment Entry MUST Require Explicit Checkout Permission
The staged payment flow SHALL only be available to users who have `pos.checkout.payment`. Users who can access the POS shell but lack checkout permission MUST NOT be allowed to begin, recover, or continue staged payment.

#### Scenario: POS-shell user without checkout permission cannot start payment
- **WHEN** a user has `pos.sell` but lacks `pos.checkout.payment` on the POS sell screen
- **THEN** the system MUST hide or disable payment entry controls
- **AND** the staged payment modal MUST NOT be presented

#### Scenario: Stage-payment endpoints reject missing checkout permission
- **WHEN** a user without `pos.checkout.payment` calls staged payment or payment-chain endpoints
- **THEN** the system MUST reject the request as forbidden
- **AND** the system MUST NOT create or mutate the payment chain session state

#### Scenario: Authorized checkout user uses staged payment normally
- **WHEN** a user with `pos.checkout.payment` begins staged payment from the POS sell screen
- **THEN** the existing staged payment flow MUST continue unchanged

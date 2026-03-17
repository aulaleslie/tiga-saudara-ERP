# pos-staged-checkout-wiring Specification

## Purpose
TBD - created by archiving change enable-pos-multi-stage-payment-flow. Update Purpose after archive.
## Requirements
### Requirement: Activate staged payment from checkout button
The "Pilih Pembayaran" button SHALL open the multi-stage sequential payment modal instead of the single-batch payment modal. When the user clicks the button, a cart-scoped token SHALL be generated (or reused if reload recovery), and the staged payment module SHALL be initialized with the token and cart grand total.

#### Scenario: Fresh cart checkout
- **WHEN** user adds items to cart and clicks "Pilih Pembayaran"
- **THEN** a new UUID token is generated, staged payment modal opens with remainder = cart grand_total, and user can select first payment method

#### Scenario: Reload during incomplete payment
- **WHEN** user has committed 1+ payment stages and refreshes the page
- **THEN** the modal auto-opens at the next stage with full payment chain visible and remainder updated

#### Scenario: All payments complete
- **WHEN** remainder becomes 0 (or negative for overpayment)
- **THEN** staged modal hides and gratitude modal shows with "Lanjut Jualan" button

### Requirement: Cart token is passed to staged payment module
The JavaScript function `PosStagedPayment.openModal(cartToken, grandTotal)` SHALL accept the cart token and grand total amount. The module SHALL use these to initialize the payment chain without fetching a Sale record.

#### Scenario: Module initialization with token
- **WHEN** `PosStagedPayment.openModal(token, grandTotal)` is called
- **THEN** module initializes `paymentChain.remainder = grandTotal` and sets session key prefix to `payment_chain_{token}`

#### Scenario: All API calls use token
- **WHEN** user submits a payment stage
- **THEN** POST to `/api/pos/sell/checkout/stage-payment` includes `cart_token` (not `sale_id`)

### Requirement: Stage payment API accepts cart token
The `POST /api/pos/sell/checkout/stage-payment` endpoint SHALL accept `cart_token` (instead of `sale_id`) as a required parameter. No Sale lookup is needed; the `grand_total` is passed from the frontend.

#### Scenario: Stage payment with valid token
- **WHEN** client POSTs `{ cart_token, payment_method_id, amount, ... }`
- **THEN** endpoint stores SalePayment record and updates session `payment_chain_{cart_token}`, returns remainder

#### Scenario: Stage payment with missing token
- **WHEN** client POSTs without `cart_token`
- **THEN** endpoint returns 422 validation error

### Requirement: Reload recovery checks payment chain
On page load (DOMContentLoaded), if `currentSnapshot.staged_payment_token` exists, the system SHALL call `GET /api/pos/sell/checkout/payment-chain?cart_token=XXX` and if `has_chain: true`, auto-open the staged modal with recovered state.

#### Scenario: Reload with active payment chain
- **WHEN** page reloads after first stage payment committed
- **THEN** GET /api/pos/sell/checkout/payment-chain returns payment chain, modal opens with full history visible

#### Scenario: Reload with no payment chain
- **WHEN** page reloads with staged_payment_token but no payments in session
- **THEN** GET /api/pos/sell/checkout/payment-chain returns `has_chain: false`, POS displays normal cart state


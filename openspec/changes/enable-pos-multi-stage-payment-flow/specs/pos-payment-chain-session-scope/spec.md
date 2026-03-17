## ADDED Requirements

### Requirement: Cart snapshot includes staged payment token
The `/pos/sell/cart` endpoint's response SHALL include `staged_payment_token` field in the `cart_snapshot`. If the token does not exist in session, the endpoint SHALL generate a new UUID token, store it in the cart session, and return it.

#### Scenario: Fresh cart includes new token
- **WHEN** user views cart without prior staged_payment_token
- **THEN** cartShow returns `staged_payment_token: "uuid-xxx"` in cart_snapshot

#### Scenario: Reload cart preserves token
- **WHEN** user reloads page during active payment chain
- **THEN** cartShow returns the same `staged_payment_token` that was stored in session

### Requirement: Session key uses cart token instead of sale ID
Payment chains SHALL be keyed by `payment_chain_{cart_token}` in the Laravel session (not `payment_chain_{sale_id}`). This allows the flow to work before Sales are created.

#### Scenario: Stage payment stores chain under token
- **WHEN** user submits first stage payment with token "abc-123"
- **THEN** backend stores `payment_chain_abc-123` in session with committed payment record

#### Scenario: Second stage reads same session key
- **WHEN** user submits second stage payment with same token "abc-123"
- **THEN** backend appends to existing `payment_chain_abc-123`, incrementing stage_order

### Requirement: Get payment chain API accepts cart token
The `GET /api/pos/sell/checkout/payment-chain` endpoint SHALL accept `cart_token` query parameter. It SHALL return the payment chain from session `payment_chain_{cart_token}`, or `has_chain: false` if not found.

#### Scenario: Get chain with token
- **WHEN** GET `/api/pos/sell/checkout/payment-chain?cart_token=abc-123`
- **THEN** returns `{ has_chain: true, payment_chain: [...], remainder: 500000 }`

#### Scenario: Get chain with non-existent token
- **WHEN** GET `/api/pos/sell/checkout/payment-chain?cart_token=invalid`
- **THEN** returns `{ has_chain: false }`

### Requirement: Delete payment chain clears session
The `DELETE /api/pos/sell/checkout/payment-chain` endpoint SHALL accept `cart_token` and remove `payment_chain_{cart_token}` from session. This is used when user cancels payment before first commit.

#### Scenario: Clear chain when returning to cart
- **WHEN** user clicks close button on empty staged modal and calls DELETE with token
- **THEN** session key `payment_chain_{cart_token}` is removed, next stage-payment will start fresh

### Requirement: Session chain structure uses correct field names
Each payment entry in the session chain `payment_chain_{cart_token}.payments[]` SHALL have fields: `id` (SalePayment ID), `stage_order`, `method_id`, `method_name`, `amount`, `edc_reference` (nullable).

#### Scenario: Stage payment adds entry to chain
- **WHEN** stagePayment endpoint commits a CASH payment of 500,000
- **THEN** session chain includes entry: `{ id: 123, stage_order: 1, method_id: 5, method_name: "CASH", amount: 500000, edc_reference: null }`

#### Scenario: Stage payment adds non-cash with reference
- **WHEN** stagePayment endpoint commits a BRI payment with EDC reference "ABC123"
- **THEN** session chain includes entry: `{ ..., method_id: 3, method_name: "BRI", edc_reference: "ABC123" }`

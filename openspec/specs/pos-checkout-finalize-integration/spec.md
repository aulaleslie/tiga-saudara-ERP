# pos-checkout-finalize-integration Specification

## Purpose
TBD - created by archiving change enable-pos-multi-stage-payment-flow. Update Purpose after archive.
## Requirements
### Requirement: Gratitude modal button triggers finalize
After all payment stages are complete (remainder = 0 or overpayment), the gratitude modal displays with "Lanjut Jualan" button. Clicking this button SHALL call `POST /pos/sell/checkout/finalize` with `{ cart_token, idempotency_key }`.

#### Scenario: User clicks Lanjut Jualan
- **WHEN** payment chain is complete and user clicks "Lanjut Jualan" button in gratitude modal
- **THEN** POST /pos/sell/checkout/finalize is called with cart_token and idempotency_key

#### Scenario: Finalize success
- **WHEN** finalize responds with 201 status and `sale_id`
- **THEN** receipt opens in new tab, cart is cleared, POS returns to initial state

#### Scenario: Finalize error
- **WHEN** finalize responds with 422 or 500 error
- **THEN** error message is displayed in modal and user can retry

### Requirement: checkoutFinalize reads cart token and payment chain
The `POST /pos/sell/checkout/finalize` endpoint SHALL accept `cart_token` in the request. It SHALL read `payment_chain_{cart_token}` from session, map payment entries to normalized format, and pass to finalize service.

#### Scenario: Finalize reads payment chain from session
- **WHEN** POST /pos/sell/checkout/finalize with `{ cart_token: "abc-123", idempotency_key: "..." }`
- **THEN** controller reads session `payment_chain_abc-123`, finds 2 payments (BRI 500k + CASH 500k)

#### Scenario: Payment chain mapping
- **WHEN** session has entry `{ method_id: 5, amount: 500000, edc_reference: null }`
- **THEN** is mapped to `{ payment_method_id: 5, amount_paid: 500000, reference: null }` before finalize service

### Requirement: Field name normalization for finalize
Before calling `FinalizePosCheckoutService::finalize()`, each payment entry in the session chain SHALL be mapped from `method_id`/`amount` to `payment_method_id`/`amount_paid` to match the normalization service's expected field names.

#### Scenario: Single payment normalization
- **WHEN** session chain has 1 entry with `{ method_id: 1, amount: 1000000 }`
- **THEN** is transformed to `{ payment_method_id: 1, amount_paid: 1000000 }` for normalization

#### Scenario: Multi-payment normalization
- **WHEN** session chain has 3 entries (BRI, BNI, CASH)
- **THEN** all entries are mapped from method_id/amount to payment_method_id/amount_paid, array is passed to finalize

### Requirement: Finalize clears payment chain from session
After successful finalize (201 status), the `payment_chain_{cart_token}` entry SHALL be removed from the session.

#### Scenario: Session cleared on success
- **WHEN** finalize completes with 201 status
- **THEN** session()->forget("payment_chain_abc-123") is called, payment chain is removed

#### Scenario: Session preserved on error
- **WHEN** finalize fails with 422 error
- **THEN** session `payment_chain_{cart_token}` is NOT cleared, user can retry same stage

### Requirement: Mapping session payments for receipt consistency
The `checkoutFinalize` logic SHALL map session payments for the finalize service, but the receipt generation logic SHALL use the `amount` accessor on `PosCheckoutPayment` entities to ensure consistency with the database schema (`amount_minor_units`).

#### Scenario: Receipt nominal mapping
- **WHEN** `PosReceiptService::getReceiptData()` loads `PosCheckoutPayment`
- **THEN** it MUST use `$payment->amount` to retrieve the decimal value, not `$payment->amount_paid`

### Requirement: Indonesian Error Messages for Checkout
Exception messages in the POS checkout finalization flow must be in Bahasa Indonesia.

#### Scenario: Empty Cart Error
- **WHEN** Finalizing checkout with an empty cart
- **THEN** The system returns 'Keranjang harus berisi setidaknya satu item baris.' instead of 'Cart must contain at least one line item.'

#### Scenario: Invalid POS Session Error
- **WHEN** Finalizing checkout with an invalid session context
- **THEN** The system returns 'Konteks sesi POS yang aktif tidak valid.' instead of 'Active POS session context is invalid.'

### Requirement: checkoutFinalize calculates change from total paid amount
The finalization logic SHALL calculate the `change_total` based on the difference between the total amount paid across all payment methods (`paid_total`) and the grand total of the checkout. For multi-payment checkouts, this calculation MUST NOT be limited to only the cash component.

#### Scenario: Change calculation for mixed payments
- **WHEN** user pays MANDIRI 10,000,000 and CASH 3,000,000 for a 12,000,000 grand total
- **THEN** `change_total` is calculated as 1,000,000 (13,000,000 total paid - 12,000,000 grand total)

### Requirement: Accurate cash event emission for change
When a checkout results in change being given to the customer, the system SHALL emit a `PosSessionCashEvent` with `event_type: EVENT_CHANGE_OUT` and `direction: DIRECTION_OUT`. This event MUST be emitted for both single-payment and multi-payment checkouts to ensure the `expected_cash_total` is correctly decremented.

#### Scenario: Change event for multi-payment
- **WHEN** a multi-payment checkout is finalized with 1,000,000 change
- **THEN** a `PosSessionCashEvent` is created with type `EVENT_CHANGE_OUT`, amount 1,000,000, and direction `OUT`

### Requirement: Checkout finalization SHALL require checkout-authorized POS bundles
The `POST /pos/sell/checkout/finalize` flow SHALL only complete for users whose POS bundle includes checkout authority. Supported `cashier` and `manager` bundles SHALL be allowed to finalize checkout, but cashier SHALL require an active terminal assignment while manager SHALL NOT. Supported `floor staff` SHALL NOT be allowed to finalize checkout even when they can access the POS shell, save drafts, or load drafts.

#### Scenario: Floor staff finalize attempt is rejected
- **WHEN** a user in the supported `floor staff` bundle submits checkout finalization
- **THEN** the system MUST reject the request
- **AND** the cart or payment-chain state MUST remain recoverable for an authorized user to continue

#### Scenario: Cashier finalizes prepared transaction
- **WHEN** a user in the supported `cashier` bundle completes the payment chain for a prepared transaction from an active terminal-assigned session
- **THEN** the system MUST finalize checkout successfully

#### Scenario: Cashier finalize attempt is rejected without terminal assignment
- **WHEN** a user in the supported `cashier` bundle submits checkout finalization from an active session that has no terminal assigned
- **THEN** the system MUST reject the request
- **AND** the cart or payment-chain state MUST remain recoverable for an authorized user to continue

#### Scenario: Manager finalizes during operational intervention
- **WHEN** a user in the supported `manager` bundle completes checkout for an authorized transaction, including from a session without terminal assignment
- **THEN** the system MUST allow finalization without relying on owner-only bypass

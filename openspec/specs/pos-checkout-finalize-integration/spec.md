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

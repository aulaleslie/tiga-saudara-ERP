# POS Multi-Stage Sequential Payment API Documentation

**Version:** 1.0
**Updated:** 2026-03-17
**Status:** Active

---

## Overview

The multi-stage payment flow enables customers to pay for POS transactions using multiple payment methods in sequence (e.g., 500k via BRI transfer + 500k via BNI transfer + 500k in cash = 1.5M total). Each payment stage is committed to the database individually, and users can pause/reload and resume the payment process.

### Key Concepts

- **Cart Token (`staged_payment_token`)**: A UUID generated per shopping cart session. Used as the identifier for the payment chain instead of a Sale ID.
- **Payment Chain**: A session-based collection of committed payments (database records) plus the remaining balance to be paid.
- **Remainder**: The amount still outstanding after one or more payment stages have been committed.
- **Idempotency**: Duplicate requests with the same `idempotency_key` return the same response without creating duplicate payments.

---

## Endpoints

### 1. Stage Payment: Submit a Single Payment Stage

**Endpoint:** `POST /pos/sell/checkout/stage-payment`

**Description:** Accept a single payment stage from the user. Commit the payment to the database, update the session-based payment chain, and return the new remainder.

**Authentication:** Required (Bearer token)

**Request Body:**

```json
{
  "cart_token": "550e8400-e29b-41d4-a716-446655440000",
  "payment_method_id": 1,
  "amount": 500000,
  "edc_reference": "TRF12345678",
  "grand_total": 1500000
}
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `cart_token` | string (UUID) | ✅ | Cart token from the staged_payment_token in cart snapshot |
| `payment_method_id` | integer | ✅ | Payment method ID (e.g., 1 for CASH, 2 for BRI Transfer) |
| `amount` | numeric | ✅ | Amount to pay in this stage (in IDR, 0.01+ minimum) |
| `edc_reference` | string | ❌ | EDC/transaction reference (required for non-cash methods) |
| `grand_total` | numeric | ✅ | Total sale amount (used to initialize session chain) |

**Response:** `201 Created`

```json
{
  "payment_chain": {
    "payments": [
      {
        "method_id": 1,
        "amount": 500000,
        "edc_reference": null,
        "stage_order": 1,
        "created_at": "2026-03-17T10:30:45Z"
      }
    ],
    "remainder": 1000000,
    "staged_at": "2026-03-17T10:30:00Z"
  },
  "remainder": 1000000
}
```

**Error Responses:**

- `422 Unprocessable Entity` - Validation failed (e.g., amount exceeds remainder, missing EDC reference)
- `400 Bad Request` - Invalid cart token format

**Common Errors:**

| Code | Message | Cause |
|------|---------|-------|
| `AMOUNT_EXCEEDS_REMAINDER` | Amount exceeds remaining balance | Payment amount > remainder |
| `EDC_REFERENCE_REQUIRED` | EDC reference is required for this payment method | Non-cash method without reference |
| `INVALID_REFERENCE_FORMAT` | Reference must be alphanumeric, 1-20 characters | Invalid format |

---

### 2. Get Payment Chain: Retrieve Current Payment Session

**Endpoint:** `GET /pos/sell/checkout/payment-chain?cart_token={token}`

**Description:** Retrieve the current payment chain from the session. Used for recovery after page reload during multi-stage checkout.

**Authentication:** Required (Bearer token)

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `cart_token` | string (UUID) | ✅ | Cart token |

**Response:** `200 OK`

```json
{
  "has_chain": true,
  "payment_chain": {
    "payments": [
      {
        "method_id": 1,
        "amount": 500000,
        "edc_reference": null,
        "stage_order": 1,
        "created_at": "2026-03-17T10:30:45Z"
      },
      {
        "method_id": 2,
        "amount": 500000,
        "edc_reference": "BRI001",
        "stage_order": 2,
        "created_at": "2026-03-17T10:35:22Z"
      }
    ],
    "remainder": 500000,
    "staged_at": "2026-03-17T10:30:00Z"
  }
}
```

**Response (No Chain):** `200 OK`

```json
{
  "has_chain": false,
  "payment_chain": null
}
```

---

### 3. Reset Payment Chain: Clear Session Payment State

**Endpoint:** `DELETE /pos/sell/checkout/payment-chain?cart_token={token}`

**Description:** Clear the session-based payment chain. Used when the user clicks "Back to Cart" before any payments are committed, or to clean up a stale session.

**Authentication:** Required (Bearer token)

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `cart_token` | string (UUID) | ✅ | Cart token |

**Response:** `200 OK`

```json
{
  "message": "Payment chain cleared."
}
```

---

### 4. Finalize Checkout: Create Sale and Complete Transaction

**Endpoint:** `POST /pos/sell/checkout/finalize`

**Description:** After all payment stages are committed, call this endpoint to finalize the checkout. Creates the Sale record with all committed payments and generates the receipt.

**Authentication:** Required (Bearer token)

**Request Body:**

```json
{
  "cart_token": "550e8400-e29b-41d4-a716-446655440000",
  "idempotency_key": "FINALIZE-2026-03-17-001"
}
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `cart_token` | string (UUID) | ✅ | Cart token (payment chain scope) |
| `idempotency_key` | string | ✅ | Unique key for idempotency (prevents duplicate checkouts) |

**Response:** `201 Created`

```json
{
  "status": "POSTED",
  "sale_id": 12345,
  "pos_checkout_id": 6789,
  "paid_total": 1500000.0,
  "change_total": 0.0,
  "idempotent_replay": false,
  "receipt_url": "/pos/sell/checkout/6789/receipt"
}
```

**Error Responses:**

- `422 Unprocessable Entity` - Validation failed (incomplete cart, payment policy violation)
- `409 Conflict` - Idempotency mismatch (same key but different payment composition)
- `500 Internal Server Error` - Posting failure (inventory, accounting)

---

## Frontend Integration

### Initialization

```javascript
// In sell.blade.php, after page load:
PosStagedPayment.initialize({
  modalElement: document.getElementById('pos-staged-checkout-modal'),
  methodSearchInput: document.getElementById('staged-method-search'),
  methodResults: document.getElementById('staged-method-results'),
  paymentChainList: document.getElementById('staged-payment-chain'),
  remainderLabel: document.getElementById('staged-remainder-amount'),
  amountInput: document.getElementById('staged-amount-input'),
  edcRefInput: document.getElementById('staged-edc-reference'),
  edcRefContainer: document.getElementById('staged-edc-reference-container'),
  submitButton: document.getElementById('staged-payment-submit'),
  spinner: document.getElementById('staged-payment-spinner'),
  errorAlert: document.getElementById('staged-payment-error'),
});

// Load payment methods from API
PosStagedPayment.loadPaymentMethods();

// Set the finalize callback
PosStagedPayment.setOnComplete(async function(changeAmount) {
  // POST to /pos/sell/checkout/finalize
  // Show receipt, clear cart, reset UI
});
```

### Opening the Modal

```javascript
// Get cart token and grand total from current snapshot
const cartToken = currentSnapshot.staged_payment_token;
const grandTotal = currentSnapshot.grand_total;

// Open modal (triggers reload recovery if needed)
PosStagedPayment.openModal(cartToken, grandTotal);
```

### Payment Method Search

The module provides built-in payment method search:

```javascript
// Fetch payment methods from /pos/sell/payment-methods/search
// Triggered on modal focus or on initialization fallback
```

---

## Session Management

### Session Key Pattern

Payment chains are stored in the Laravel session:

```
Key: payment_chain_{cart_token}
Value: {
  payments: [ ... ],     // Array of committed payments
  remainder: number,     // Remaining balance
  staged_at: string      // ISO 8601 timestamp
}
```

### Session Scope

- **Per Cart Token**: Each cart has its own payment chain in the session
- **Per Browser Session**: Session expires with the PHP session (default 2 hours)
- **Automatic Cleanup**: Session is cleared after finalize completes successfully

---

## Error Handling

### Validation Errors

```json
{
  "code": "VALIDATION_ERROR",
  "message": "Validation failed",
  "details": {
    "amount": ["Amount must be greater than 0"]
  }
}
```

### Idempotency Mismatch

```json
{
  "code": "IDEMPOTENCY_MISMATCH",
  "message": "Idempotency key was used with different payment composition"
}
```

### EDC Reference Validation

```json
{
  "code": "EDC_REFERENCE_INVALID",
  "message": "EDC reference format is invalid"
}
```

---

## Best Practices

1. **Always Use Cart Token**: Never use Sale ID in the frontend. Sale doesn't exist until finalize.
2. **Load Payment Methods Early**: Call `PosStagedPayment.loadPaymentMethods()` during initialization to avoid delays.
3. **Handle Reload Recovery**: The module automatically handles page reload during payment. Verify `has_chain` in your recovery logic.
4. **Validate Idempotency Key**: Ensure idempotency keys are unique per transaction (timestamp + user + nonce).
5. **Clear Session on Failure**: If finalize fails, users can click "Back to Cart" to clear the session and restart.

---

## Testing Scenarios

### Scenario 1: Single Cash Payment

1. Add product to cart (1M)
2. Click "Checkout"
3. Select CASH payment method
4. Enter amount: 1,000,000
5. Submit → Remainder = 0 → Finalize → Receipt

### Scenario 2: Multi-Stage Payment

1. Add product to cart (1.5M)
2. Click "Checkout"
3. Stage 1: BRI 500k + reference → Remainder = 1M
4. Stage 2: BNI 500k + reference → Remainder = 500k
5. Stage 3: CASH 500k → Remainder = 0 → Finalize

### Scenario 3: Reload Recovery

1. Start Stage 1: BRI 500k
2. Click "Next" (or refresh page accidentally)
3. Modal reopens with BRI 500k already committed → Continue with Stage 2
4. Complete remaining stages

### Scenario 4: Back to Cart

1. Start multi-stage payment
2. Click "Close" (before any payments) → Back to Cart
3. Session chain cleared, continue shopping

---

## Changelog

| Date | Change |
|------|--------|
| 2026-03-17 | Initial multi-stage payment flow implementation |

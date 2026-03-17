## Context

The POS multi-payment finalization flow has two code paths:

1. **Single-payment path** (legacy): `normalizePayment()` returns a flat structure:
   ```php
   {
       'payment_method_id' => int,
       'amount_paid' => float,
       'reference' => ?string,
       'is_cash' => bool,
       'requires_reference' => bool
   }
   ```

2. **Multi-payment path** (new): `normalizeMultiPayment()` returns a nested structure:
   ```php
   {
       'is_multi_payment' => true,
       'payments' => [...],
       'amount_paid' => float,
       'total_cash_minor_units' => int,
       'canonical_payment_hash' => string,
       'payment_method_id' => int,
       'reference' => ?string
       // ← MISSING: 'is_cash'
   }
   ```

The rest of the finalization logic expects `$payment['is_cash']` to be present at the root level:
- Line 616: Determines change calculation for legacy single-payment
- Line 660: Determines cash drawer event recording
- Line 611-614: Determines cash amount for session

Without `is_cash`, these checks fail with "Undefined array key" errors.

## Goals / Non-Goals

**Goals:**
- Add `is_cash` to the multi-payment return structure for consistency
- Maintain backward compatibility with single-payment path
- Eliminate conditional logic that checks `is_multi_payment` flag before accessing `is_cash`
- Allow all multi-payment checkouts to complete finalization

**Non-Goals:**
- Change the database schema or payment method entity
- Modify the payment normalization service itself
- Alter how cash amounts are calculated
- Change payment allocation logic across split groups

## Decisions

### Decision: Extract `is_cash` from First Payment

**Choice**: In `normalizeMultiPayment()`, after normalizing all payments, extract `is_cash` from `$normalizedPayments[0]` and add it to the root level of the return structure.

**Rationale**:
- The first payment is already designated as the "primary" payment (used for `payment_method_id` and `reference`)
- This maintains consistency with single-payment structure where a single `is_cash` flag exists
- Downstream code (cash event recording, change calculation) can treat multi-payment like single-payment without branching
- The full cash situation for multi-payment is still tracked via `total_cash_minor_units`; the `is_cash` flag at root is just for simple "is any payment cash?" checks

**Alternatives considered**:
- A. Add `is_multi_payment` checks throughout: Would increase code complexity and fragility
- B. Store `is_cash` array for all payments: Would require reworking cash event logic
- C. Make `is_cash` nullable: Creates inconsistency; some checkouts have no value for it

## Risks / Trade-offs

**Risk**: Multiple cash/non-cash payments might create misleading `is_cash` flag
- **Mitigation**: The flag is only used for simple checks (cash drawer, event type). Complex logic uses `total_cash_minor_units`. Reporting queries that need full breakdown join to `pos_checkout_payments` table where individual payment details are stored.

**Risk**: First payment method might not be representative of the transaction
- **Mitigation**: The "primary" method is only for ledger association and simple compatibility. The first payment is semantically reasonable (typically the payment method initiated by user first). Full payment details are in `pos_checkout_payments`.

**Trade-off**: Store only "first payment's cash status" at root, lose detail about other payments
- **Mitigation**: This is intentional design. The root-level `is_cash` is for convenience and backward compatibility. Detailed payment information is preserved in the `payments[]` array and in `pos_checkout_payments` table.

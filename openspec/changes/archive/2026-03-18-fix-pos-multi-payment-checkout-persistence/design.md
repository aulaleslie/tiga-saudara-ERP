## Context

The POS multi-payment finalization flow has two separate structures for handling payments:

1. **Single-payment path** (legacy): `normalizePayment()` returns `{ payment_method_id, amount_paid, reference, is_cash, requires_reference }`
2. **Multi-payment path** (new): `normalizeMultiPayment()` returns `{ is_multi_payment: true, payments: [...], amount_paid, total_cash_minor_units, canonical_payment_hash }`

The `resolveCheckoutLedger()` method creates the `PosCheckout` ledger record and attempts to store `$payment['payment_method_id']` and `$payment['reference']` directly. This works for single-payment but fails for multi-payment because those fields exist only inside `$payment['payments'][0]`, not at the root level.

The posting adapter (`InlinePosCheckoutPostingAdapter`) already handles this by extracting the first payment method as the "primary" payment method for the sale_payment record. We need to align the finalization service with this pattern.

## Goals / Non-Goals

**Goals:**
- Fix the "Undefined array key 'payment_method_id'" error on multi-payment checkouts
- Extract the first payment method and reference from multi-payment structures for ledger persistence
- Maintain backward compatibility with single-payment checkouts
- Align with existing posting adapter pattern for consistency

**Non-Goals:**
- Change the database schema (use existing `payment_method_id` and `payment_reference` fields on `pos_checkouts`)
- Modify the payment normalization service itself
- Change how individual payments are stored in `pos_checkout_payments` (already working correctly)
- Alter payment allocation logic across split groups (separate concern)

## Decisions

### Decision 1: Extract First Payment as Primary

**Choice**: In `normalizeMultiPayment()`, after building the normalized payments array, extract `payment_method_id` and `reference` from `payments[0]` and add them to the return structure at the root level.

**Rationale**:
- This mirrors the pattern already in use in `InlinePosCheckoutPostingAdapter` (lines 57-58)
- Minimizes code changes (single place to fix)
- Aligns multi-payment structure with single-payment structure for consistency
- The "first" payment method is semantically reasonable as the "primary" method for the checkout
- `resolveCheckoutLedger()` needs no modification—it works as-is once the root-level fields are present

**Alternatives considered**:
- A. Extract first payment in `resolveCheckoutLedger()`: Would require defensive checks and more code churn
- B. Make `payment_method_id` nullable for multi-payment: Creates inconsistency; some checkouts have no primary method
- C. Store only aggregate data (no single method): Breaks existing reports and the posting adapter's assumptions

### Decision 2: Preserve Payment Method Metadata

**Choice**: Store only the method ID and reference, not the full payment object or is_cash status.

**Rationale**:
- `pos_checkouts.payment_method_id` is a foreign key; stores scalar value
- `pos_checkouts.payment_reference` stores the string reference
- Individual payment details (is_cash, amount, etc.) are already in `pos_checkout_payments` records
- Posting adapter and reporting queries only need the method ID to look up full details via JOIN
- No additional data model changes needed

### Decision 3: Maintain Order Preservation

**Choice**: Use the first payment in the sequence order as primary (order is preserved from frontend submission).

**Rationale**:
- Payment sequence order is already tracked in `PosCheckoutPayment.sequence_order`
- First payment is typically the primary instrument (e.g., card before cash change)
- Consistent with posting adapter behavior

## Risks / Trade-offs

**Risk**: Multiple payment methods with different requirements might confuse reporting.
- **Mitigation**: The primary method (first) is only for ledger association. Reporting queries join to `pos_checkout_payments` to get the full picture; they don't rely solely on the checkout's primary method.

**Risk**: Page reload during multi-payment input could lose context.
- **Mitigation**: Not a concern here—this fix is for finalization. Session state recovery is handled by `pos-payment-stage-persistence` spec.

**Trade-off**: Stores only "first" payment as primary, loses other payment methods on the checkout record.
- **Mitigation**: This is intentional. Individual payments are stored in `pos_checkout_payments`; the checkout's primary field is for simple queries and compatibility. More complex queries join to the detailed payments table.

## Implementation Strategy

1. Modify `normalizeMultiPayment()` to extract the first payment's method ID and reference
2. Return them at the root level: `{ ..., payment_method_id: int, reference: ?string }`
3. No changes to `resolveCheckoutLedger()` or other code paths required
4. Verify single-payment path unaffected (no `payment_method_id` or `reference` fields added to multi-payment when not present)

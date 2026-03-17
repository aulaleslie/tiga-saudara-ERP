# pos-multi-stage-payment DELTA Specification

## Purpose

Updates to the multi-payment normalization to ensure compatibility with checkout ledger persistence by including primary payment method and reference at the root level of the normalized payment structure.

## MODIFIED Requirements

### Requirement: Multi-Payment Normalization Structure

**ORIGINAL**: The `normalizeMultiPayment()` service returns a structure containing only `{ is_multi_payment, payments[], amount_paid, total_cash_minor_units, canonical_payment_hash }` with individual payment methods nested inside the payments array.

**UPDATED**: The `normalizeMultiPayment()` service SHALL return a structure with the primary payment method and reference extracted from the first payment in the sequence at the root level. The returned structure SHALL include `{ is_multi_payment, payments[], payment_method_id, reference, amount_paid, total_cash_minor_units, canonical_payment_hash }`. This allows downstream ledger persistence code to access the primary payment method without array manipulation.

#### Scenario: Normalized structure includes primary method at root level
- **WHEN** `normalizeMultiPayment()` processes payments [Card 100,000, Cash 50,000]
- **THEN** returned structure includes both `payment_method_id: <card_id>` at root level AND `payments[0].payment_method_id: <card_id>` in the array

#### Scenario: Primary reference is extracted
- **WHEN** `normalizeMultiPayment()` processes payments where first payment has reference "TXN123"
- **THEN** returned structure includes `reference: "TXN123"` at root level for ledger persistence

#### Scenario: Backward compatibility for ledger persistence
- **WHEN** `resolveCheckoutLedger()` receives a normalized multi-payment structure
- **THEN** it can access `$payment['payment_method_id']` and `$payment['reference']` directly without checking for multi-payment arrays

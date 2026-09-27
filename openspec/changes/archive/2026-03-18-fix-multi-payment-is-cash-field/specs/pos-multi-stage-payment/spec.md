# pos-multi-stage-payment DELTA Specification

## Purpose

Completes the multi-payment normalization by ensuring the normalized payment structure includes all required fields (`is_cash`) for consistent handling throughout the finalization pipeline.

## MODIFIED Requirements

### Requirement: Multi-payment structure includes all required fields

**ORIGINAL**: The `normalizeMultiPayment()` method returns a structure with `is_multi_payment`, `payments[]`, `amount_paid`, `total_cash_minor_units`, and `canonical_payment_hash`.

**UPDATED**: The `normalizeMultiPayment()` method SHALL return a structure that includes `is_cash` at the root level, extracted from the first payment method. This field SHALL be identical to `payments[0]['is_cash']` and SHALL be present for all multi-payment checkouts to ensure consistent handling with single-payment checkouts throughout the finalization flow (change calculation, cash event recording, session tracking).

#### Scenario: Multi-payment structure includes is_cash field
- **WHEN** a multi-payment checkout is normalized with cash and non-cash methods (cash first)
- **THEN** the returned structure includes `'is_cash' => true` at the root level
- **AND** `is_cash` equals `payments[0]['is_cash']` which is `true`

#### Scenario: Multi-payment structure with non-cash primary method
- **WHEN** a multi-payment checkout is normalized with non-cash method first
- **THEN** the returned structure includes `'is_cash' => false` at the root level
- **AND** `is_cash` reflects the first payment method's cash status

#### Scenario: Downstream code handles is_cash consistently
- **WHEN** finalization logic accesses `$payment['is_cash']` for multi-payment checkout
- **THEN** it successfully reads the value without "Undefined array key" errors
- **AND** change calculation and cash event recording work identically for single and multi-payment checkouts

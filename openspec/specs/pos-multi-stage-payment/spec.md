# pos-multi-stage-payment Specification

## Purpose
Multi-payment checkout flow that allows customers to split payments across multiple methods, with proper remainder tracking and validation per method type.

## Requirements

### Requirement: Remainder is recalculated after each stage
After each payment stage is committed, the system SHALL recalculate remainder as `grand_total - sum(all_committed_amounts)` using the ORIGINAL grand total value from the cart snapshot, not the running remainder. The frontend SHALL send the original grand total value, not the intermediate remainder, to the backend for each stage submission.

#### Scenario: Remainder correctly calculated after first stage
- **WHEN** user submits first payment of 40,000 with original grand total of 60,000
- **THEN** remainder returned as 20,000 (not 60,000)

#### Scenario: Remainder correctly calculated after second stage
- **WHEN** first remainder was 20,000, grand total is 60,000, and user submits second payment of 20,000
- **THEN** remainder returned as 0 (60,000 - 40,000 - 20,000 = 0)

#### Scenario: Frontend sends correct grand total value
- **WHEN** user is on payment stage 2 and submits payment
- **THEN** the payload sent to backend includes original grand total (60,000), not recalculated grand total (remainder + amount)

### Requirement: Payment amount validation varies by method type

Cash payment methods allow overpayment; non-cash methods restrict amount to not exceed remainder. See `payment-method-amount-validation` spec for full details.

#### Scenario: Cash payment can exceed remainder
- **WHEN** payment method is CASH and remainder is 100,000 and user enters 120,000
- **THEN** validation passes and system shows change of 20,000

#### Scenario: Non-cash payment cannot exceed remainder
- **WHEN** payment method is non-cash and remainder is 100,000 and user enters 120,000
- **THEN** validation rejects with error message

### Requirement: Multi-payment structure includes all required fields

The `normalizeMultiPayment()` method SHALL return a structure that includes `is_cash` at the root level, extracted from the first payment method. This field SHALL be identical to `payments[0]['is_cash']` and SHALL be present for all multi-payment checkouts to ensure consistent handling with single-payment checkouts throughout the finalization flow (change calculation, cash event recording, session tracking).

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

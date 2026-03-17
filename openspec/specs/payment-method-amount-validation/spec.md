# payment-method-amount-validation Specification

## Purpose
Defines validation rules for payment amounts based on payment method type (cash vs non-cash) to prevent invalid transactions and ensure accurate change calculation.

## ADDED Requirements

### Requirement: Payment amount validation varies by method type
The system SHALL enforce different validation rules for payment amounts depending on whether the payment method is cash (`is_cash=true`) or non-cash (`is_cash=false`). Cash methods allow overpayment for change; non-cash methods do not.

#### Scenario: Cash payment with amount less than remainder
- **WHEN** payment method is CASH and remainder is 100,000 and user enters 80,000
- **THEN** validation SHALL reject with message "Jumlah pembayaran tidak boleh kurang dari sisa pembayaran"

#### Scenario: Cash payment with amount equal to remainder
- **WHEN** payment method is CASH and remainder is 100,000 and user enters 100,000
- **THEN** validation SHALL pass and payment can be submitted

#### Scenario: Cash payment with amount greater than remainder
- **WHEN** payment method is CASH and remainder is 100,000 and user enters 120,000
- **THEN** validation SHALL pass; system calculates change as 20,000 and displays it in gratitude modal

#### Scenario: Non-cash payment with amount equal to remainder
- **WHEN** payment method is non-cash and remainder is 100,000 and user enters 100,000
- **THEN** validation SHALL pass and payment can be submitted

#### Scenario: Non-cash payment with amount less than remainder
- **WHEN** payment method is non-cash and remainder is 100,000 and user enters 80,000
- **THEN** validation SHALL pass and payment can be submitted; remainder recalculates to 20,000 for next stage

#### Scenario: Non-cash payment with amount greater than remainder
- **WHEN** payment method is non-cash and remainder is 100,000 and user enters 120,000
- **THEN** validation SHALL reject with message "Jumlah pembayaran tidak boleh lebih dari sisa pembayaran"

### Requirement: Frontend validates before submission
Client-side validation SHALL check payment amounts against remainder before allowing submission, preventing invalid requests from reaching the backend.

#### Scenario: Frontend blocks invalid non-cash overpayment
- **WHEN** user selects non-cash method and tries to enter amount 120,000 when remainder is 100,000
- **THEN** frontend validation displays error message immediately upon amount input and disables submit button

#### Scenario: Frontend blocks invalid cash underpayment
- **WHEN** user selects CASH method and tries to enter amount 80,000 when remainder is 100,000
- **THEN** frontend validation displays error message immediately upon amount input and disables submit button

### Requirement: Required EDC reference validation for non-cash methods
Non-cash payment methods that have `requires_reference=true` SHALL enforce that an EDC reference is provided and is not empty.

#### Scenario: Non-cash method without reference is rejected
- **WHEN** user selects non-cash method with `requires_reference=true` and submits without entering EDC reference
- **THEN** validation SHALL reject with message "Nomor referensi EDC wajib diisi"

#### Scenario: Non-cash method with reference is accepted
- **WHEN** user selects non-cash method with `requires_reference=true` and enters EDC reference "12345"
- **THEN** validation SHALL pass and payment can be submitted

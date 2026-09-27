# pos-multi-stage-payment DELTA Specification

## Purpose
Updates to the existing multi-stage payment flow to fix remainder calculation bug and harden validation rules.

## MODIFIED Requirements

### Requirement: Remainder is recalculated after each stage
**ORIGINAL**: After each payment stage is committed, the system SHALL recalculate remainder as `grand_total - sum(all_committed_amounts)` and return it to the frontend.

**UPDATED**: After each payment stage is committed, the system SHALL recalculate remainder as `grand_total - sum(all_committed_amounts)` using the ORIGINAL grand total value from the cart snapshot, not the running remainder. The frontend SHALL send the original grand total value, not the intermediate remainder, to the backend for each stage submission.

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
**NEW IN THIS DELTA**

Cash payment methods allow overpayment; non-cash methods restrict amount to not exceed remainder. See `payment-method-amount-validation` spec for full details.

#### Scenario: Cash payment can exceed remainder
- **WHEN** payment method is CASH and remainder is 100,000 and user enters 120,000
- **THEN** validation passes and system shows change of 20,000

#### Scenario: Non-cash payment cannot exceed remainder
- **WHEN** payment method is non-cash and remainder is 100,000 and user enters 120,000
- **THEN** validation rejects with error message

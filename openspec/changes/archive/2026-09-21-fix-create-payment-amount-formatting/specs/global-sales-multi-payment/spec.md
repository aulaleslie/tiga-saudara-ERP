# Spec Delta

## ADDED Requirements

### Requirement: Global Sales allocations use standardized payment amount formatting
Every editable monetary allocation on the global Sales payment creation form SHALL follow the `payment-amount-input-formatting` focus, blur, canonical calculation, validation, and submission behavior.

#### Scenario: Operator edits a Sales allocation
- **WHEN** an operator focuses, edits, and blurs a global Sales allocation
- **THEN** the allocation SHALL transition between raw editing and localized display without changing its canonical value
- **AND** the total allocation SHALL use that canonical value.

#### Scenario: Global Sales payment is submitted
- **WHEN** the operator submits valid formatted allocations
- **THEN** the server SHALL receive canonical numeric allocation values
- **AND** existing customer, eligibility, live-balance, and atomic settlement validation SHALL remain authoritative.


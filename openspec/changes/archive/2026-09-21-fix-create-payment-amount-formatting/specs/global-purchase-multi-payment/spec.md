# Spec Delta

## ADDED Requirements

### Requirement: Global Purchase allocations use standardized payment amount formatting
Every editable monetary allocation on the global Purchase payment creation form SHALL follow the `payment-amount-input-formatting` focus, blur, canonical calculation, validation, and submission behavior, including allocations managed across paginated table rows.

#### Scenario: Operator edits a Purchase allocation
- **WHEN** an operator focuses, edits, and blurs a global Purchase allocation
- **THEN** the allocation SHALL transition between raw editing and localized display without changing its canonical value
- **AND** the total allocation SHALL include the canonical values from all table pages.

#### Scenario: Global Purchase payment is submitted
- **WHEN** the operator submits valid formatted allocations, including allocations on a non-visible table page
- **THEN** the server SHALL receive canonical numeric values for every allocation
- **AND** existing supplier, eligibility, live-balance, and atomic settlement validation SHALL remain authoritative.


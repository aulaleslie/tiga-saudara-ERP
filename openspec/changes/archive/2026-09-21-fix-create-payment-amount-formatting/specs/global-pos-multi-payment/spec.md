# Spec Delta

## ADDED Requirements

### Requirement: Global POS allocations use standardized payment amount formatting
Every editable monetary allocation on the global POS payment creation form SHALL follow the `payment-amount-input-formatting` focus, blur, canonical calculation, validation, preview, and submission behavior.

#### Scenario: Operator edits a POS allocation
- **WHEN** an operator focuses, edits, and blurs a global POS allocation
- **THEN** the allocation SHALL transition between raw editing and localized display without changing its canonical value
- **AND** the POS payment total SHALL use that canonical value.

#### Scenario: Operator previews and submits POS allocations
- **WHEN** the operator previews or submits valid formatted POS allocations
- **THEN** the preview and submission payloads SHALL contain the same canonical numeric values
- **AND** existing POS transaction eligibility, live-balance, child-Sale expansion, and atomic settlement validation SHALL remain authoritative.


## MODIFIED Requirements

### Requirement: Tax fallback SHALL be applied for split tax bucket resolution
For taxable checkout lines, the system SHALL resolve split tax bucket in precedence order: explicit line tax, then serial-derived tax for serial-assigned lines, then source-context tax, and finally fallback policy (default tax first, otherwise latest active tax). Tax applicability SHALL be gated by source owner policy: when the source owner setting is non-PKP (`is_pkp=false`), the effective tax bucket MUST be `NON_TAX` regardless of candidate tax evidence.

#### Scenario: Serial-assigned taxable line resolves tax bucket from serial context for PKP source owner
- **WHEN** a serial-required line is sourced from a PKP owner and is taxable by assigned serial context while `line.tax_id` is null
- **THEN** the split planner MUST assign the tax bucket from the assigned serial tax context
- **AND** the line MUST NOT be classified as `NON_TAX` solely because `line.tax_id` is null.

#### Scenario: Serial-assigned taxed serial remains non-tax for non-PKP source owner
- **WHEN** a serial-required line has assigned serials with `tax_id` values but the source owner setting is non-PKP (`is_pkp=false`)
- **THEN** the effective split tax bucket MUST be `NON_TAX`
- **AND** downstream posting MUST NOT persist dispatch tax for those chunks.

#### Scenario: Non-serial taxable line without explicit tax still uses fallback order for PKP source owner
- **WHEN** a taxable non-serial line is sourced from a PKP owner and has no explicit line tax and no source-resolved tax
- **THEN** the split planner MUST apply fallback policy in order: default tax first, otherwise latest active tax.

## ADDED Requirements

### Requirement: Posted tax persistence SHALL remain consistent with planned source-owner tax policy
Finalize posting SHALL persist tax-bearing fields using the same source-owner tax policy resolved by pre-check and split planning, and MUST NOT re-derive serial tax behavior using a different heuristic.

#### Scenario: Mixed-owner checkout persists tax per owner policy
- **WHEN** a checkout contains chunks from both PKP and non-PKP source owners for the same product line
- **THEN** persisted `sale_details.product_tax_amount` and `dispatch_details.tax_id` MUST reflect only PKP-owned taxable chunks
- **AND** non-PKP owned chunks MUST be persisted as non-tax chunks.

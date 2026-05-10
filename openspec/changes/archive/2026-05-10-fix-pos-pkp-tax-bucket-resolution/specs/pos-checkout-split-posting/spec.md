## MODIFIED Requirements

### Requirement: Tax fallback SHALL be applied for split tax bucket resolution
For POS checkout split planning, the system SHALL resolve the effective split tax bucket from source owner policy and tax evidence in precedence order: explicit POS line tax, product or product-price sale tax, serial-derived tax for serial-assigned lines, allocation or stock tax, and finally fallback policy (default tax first, otherwise latest active tax). Tax applicability SHALL be gated by source owner policy and allocation bucket: when the source owner setting is PKP (`is_pkp=true`) or the allocation consumes `quantity_tax`, the effective tax bucket MUST be `TAX:<tax_id>` using fallback tax resolution when needed; when the source owner setting is non-PKP (`is_pkp=false`) and the allocation does not consume `quantity_tax`, the effective tax bucket MUST be `NON_TAX` regardless of candidate tax evidence.

#### Scenario: Serial-assigned taxable line resolves tax bucket from serial context for PKP source owner
- **WHEN** a serial-required line is sourced from a PKP owner and is taxable by assigned serial context while `line.tax_id` is null
- **THEN** the split planner MUST assign the tax bucket from the assigned serial tax context
- **AND** the line MUST NOT be classified as `NON_TAX` solely because `line.tax_id` is null.

#### Scenario: Serial-assigned taxed serial remains non-tax for non-PKP source owner
- **WHEN** a serial-required line has assigned serials with `tax_id` values but the source owner setting is non-PKP (`is_pkp=false`)
- **THEN** the effective split tax bucket MUST be `NON_TAX`
- **AND** downstream posting MUST NOT persist dispatch tax for those chunks.

#### Scenario: Non-serial PKP line without explicit tax uses fallback tax
- **WHEN** a non-serial POS line is sourced from a PKP owner and has no explicit line tax, no product sale tax, and no stock tax
- **THEN** the split planner MUST apply fallback policy in order: default tax first, otherwise latest active tax
- **AND** the generated split group MUST use `TAX:<fallback_tax_id>` instead of `NON_TAX`.

#### Scenario: Tax-bucket stock allocation without explicit tax uses fallback tax
- **WHEN** a POS allocation consumes `quantity_tax` and has no explicit line tax, no product sale tax, and no stock tax
- **THEN** the split planner MUST apply fallback policy in order: default tax first, otherwise latest active tax
- **AND** the allocation MUST be posted with a taxable split bucket and taxable dispatch context.

### Requirement: Posted tax persistence SHALL remain consistent with planned source-owner tax policy
Finalize posting SHALL persist tax-bearing fields using the same source-owner and allocation-bucket tax policy resolved by pre-check and split planning, and MUST NOT re-derive tax behavior using a different heuristic. PKP-owned POS allocations and allocations from `quantity_tax` SHALL persist `sale_details.tax_id`, `sale_details.product_tax_amount`, `dispatch_details.tax_id`, and stock bucket movement as taxable using the planned effective tax. Non-PKP owned allocations that do not consume `quantity_tax` SHALL remain non-tax. Selected bundled POS allocation parts SHALL use the parent line tax candidate, product or product-price sale tax, allocation or stock tax, or fallback tax when needed, and SHALL extract included tax only for taxable allocations.

#### Scenario: Mixed-owner checkout persists tax per owner policy
- **WHEN** a checkout contains chunks from both PKP and non-PKP source owners for the same product line
- **THEN** persisted `sale_details.product_tax_amount` and `dispatch_details.tax_id` MUST reflect PKP-owned taxable chunks and chunks allocated from `quantity_tax`
- **AND** non-PKP owned chunks not allocated from `quantity_tax` MUST be persisted as non-tax chunks.

#### Scenario: Bundle allocation uses fallback tax for PKP source owner
- **WHEN** POS split posting allocates selected bundle revenue to a PKP source owner
- **AND** the selected bundled parent row and bundled component have no explicit product or line tax candidate
- **THEN** the generated owner-specific sale detail tax amount SHALL be extracted as included tax from that allocated gross amount using fallback tax resolution
- **AND** the generated sale bundle item and dispatch context SHALL persist the planned effective tax id.

#### Scenario: Bundle allocation remains non-tax for non-PKP source owner
- **WHEN** POS split posting allocates selected bundle revenue to a non-PKP source owner
- **AND** the allocation does not consume `quantity_tax`
- **THEN** the generated owner-specific sale detail tax amount for that allocation SHALL be zero
- **AND** persisted dispatch tax for that allocation SHALL remain non-tax

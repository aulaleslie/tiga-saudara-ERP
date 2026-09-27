## MODIFIED Requirements

### Requirement: Tax fallback SHALL be applied for split tax bucket resolution
For taxable checkout lines, the system SHALL resolve split tax bucket in precedence order: explicit line tax, then serial-derived tax for serial-assigned lines, then source-context tax, and finally fallback policy (default tax first, otherwise latest active tax).

#### Scenario: Serial-assigned taxable line resolves tax bucket from serial context
- **WHEN** a serial-required line is taxable by assigned serial context and `line.tax_id` is null
- **THEN** the split planner MUST assign the tax bucket from the assigned serial tax context
- **AND** the line MUST NOT be classified as `NON_TAX` solely because `line.tax_id` is null.

#### Scenario: Non-serial taxable line without explicit tax still uses fallback order
- **WHEN** a taxable non-serial line has no explicit line tax and no source-resolved tax
- **THEN** the split planner MUST apply fallback policy in order: default tax first, otherwise latest active tax.

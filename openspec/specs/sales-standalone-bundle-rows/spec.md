## Requirements

### Requirement: Sales bundle rows SHALL support optional parent sale detail linkage
The Sales domain SHALL allow bundle component rows to exist with or without a parent `sale_details` relation. Rows without a parent relation MUST remain valid through explicit standalone context fields.

#### Scenario: Linked bundle row remains valid
- **WHEN** a bundle row has a non-null `sale_detail_id`
- **THEN** the row SHALL be treated as linked to its parent sale detail
- **AND** existing linked-row behavior SHALL remain unchanged

#### Scenario: Standalone bundle row remains valid without parent detail
- **WHEN** a bundle row has a null `sale_detail_id`
- **THEN** the row SHALL be accepted as a valid standalone bundle component row
- **AND** the row SHALL carry standalone context required for downstream Sales read behavior

### Requirement: Sales read paths SHALL use deterministic context resolution for bundle rows
Sales read-side logic that requires tax or grouping context for bundle rows SHALL resolve context using deterministic precedence: parent inheritance first, standalone self context second.

#### Scenario: Linked row uses parent-inherited context
- **WHEN** a bundle row has a non-null `sale_detail_id`
- **THEN** Sales read paths SHALL resolve tax/grouping context from the parent `sale_details` row

#### Scenario: Standalone row uses self context fallback
- **WHEN** a bundle row has a null `sale_detail_id`
- **THEN** Sales read paths SHALL resolve tax/grouping context from standalone bundle-row fields
- **AND** the row SHALL NOT be rejected solely because parent inheritance is unavailable

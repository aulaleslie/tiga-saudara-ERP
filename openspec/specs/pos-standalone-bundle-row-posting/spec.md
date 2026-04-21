## Requirements

### Requirement: POS checkout finalize SHALL persist bundle component rows in Sales
POS checkout finalize MUST persist one or more `sale_bundle_items` rows for each posted bundle component so Sales downstream flows can resolve bundle context from persisted data.

#### Scenario: Inline posting persists bundle components
- **WHEN** checkout finalize posts a cart line with `bundle_items` using inline posting
- **THEN** the system SHALL create `sale_bundle_items` rows linked to the created sale
- **AND** each persisted row SHALL include `product_id`, `quantity`, `price`, and `sub_total`

#### Scenario: Split posting persists bundle components per group
- **WHEN** checkout finalize posts bundle lines through split posting across one or more groups
- **THEN** each generated sale SHALL include persisted `sale_bundle_items` rows for its bundle components
- **AND** no split group with bundle components SHALL complete without corresponding persisted bundle rows

### Requirement: POS bundle row persistence SHALL support linked and standalone-compatible contexts
POS posting MUST persist bundle rows with deterministic context fields so rows remain valid whether parent linkage exists or is absent.

#### Scenario: Linked parent detail is available
- **WHEN** a posted bundle component belongs to a checkout line that produced a parent `sale_details` row
- **THEN** the persisted bundle row SHALL include that `sale_detail_id`
- **AND** the row SHALL also include standalone-compatible fields `tax_id`, `tax_amount`, and `line_group_key`

#### Scenario: Parent detail is not available
- **WHEN** a posted bundle component has no parent `sale_details` row in the finalized sale
- **THEN** the persisted bundle row SHALL be created with `sale_detail_id = null`
- **AND** the row SHALL remain valid by carrying deterministic standalone context including `line_group_key`

### Requirement: POS idempotent replay MUST NOT duplicate persisted bundle rows
POS finalize replay behavior MUST be idempotent for bundle-row persistence.

#### Scenario: Finalize replay with same idempotency key
- **WHEN** checkout finalize is retried with an idempotency key that already completed posting
- **THEN** the system SHALL return the existing posted result
- **AND** the system SHALL NOT insert additional `sale_bundle_items` rows for the same posted checkout

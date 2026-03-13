## ADDED Requirements

### Requirement: Split checkout customer resolution SHALL use global customer identity
For each split group, the system SHALL resolve customer identity by global customer record existence on `customers.id` and MUST NOT require `customers.setting_id` to match the split group's `source_setting_id`.

#### Scenario: Selected customer resolves across setting ownership
- **WHEN** finalize split checkout runs with `checkout.customer_id` pointing to an existing customer whose `setting_id` differs from a split group's `source_setting_id`
- **THEN** the split group customer resolution succeeds using that selected customer ID

#### Scenario: Walk-in fallback resolves across setting ownership
- **WHEN** selected customer is absent or invalid and source setting `pos_walk_in_customer_id` points to an existing customer whose `setting_id` differs from `source_setting_id`
- **THEN** the split group customer resolution succeeds using the configured walk-in customer ID

### Requirement: Split checkout unresolved failures SHALL only occur for missing customer records
The system MUST raise `CUSTOMER_UNRESOLVED` only when no valid customer record can be resolved by ID from either selected checkout customer or source walk-in fallback.

#### Scenario: Unresolved when selected and fallback customers are invalid
- **WHEN** `checkout.customer_id` is null or references a non-existent customer and source setting `pos_walk_in_customer_id` is null or references a non-existent customer
- **THEN** finalize fails with `CUSTOMER_UNRESOLVED` and actionable source details for selected and fallback resolution attempts

#### Scenario: Valid customer is not rejected for setting mismatch
- **WHEN** either selected customer ID or fallback walk-in customer ID exists globally but belongs to a different `setting_id` than the split source
- **THEN** finalize does not fail with unresolved-customer error due to setting ownership mismatch

### Requirement: Split posting ownership SHALL remain source-setting scoped
The system SHALL preserve split posting ownership behavior such that customer-owner mismatches do not alter `sales.setting_id`, transaction ownership, or source-based numbering semantics.

#### Scenario: Cross-owner customer still posts to source owner setting
- **WHEN** a split group resolves a global customer from a different setting ownership
- **THEN** posted sale and transaction ownership remain assigned to that split group's `source_setting_id`

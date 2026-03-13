# pos-checkout-split-posting Specification

## Purpose
TBD - created by archiving change implement-pos-phase-3-split-posting. Update Purpose after archive.
## Requirements
### Requirement: Checkout split groups SHALL be derived by source and tax bucket
The system SHALL derive split groups for finalized POS checkout lines using key `source_setting_id + source_location_id + tax_bucket` before posting any sale documents.

#### Scenario: Grouping mixed-source checkout lines
- **WHEN** a checkout contains lines resolved to multiple source setting/location combinations and mixed tax outcomes
- **THEN** the split planner produces one group per unique `source_setting_id + source_location_id + tax_bucket`

### Requirement: Finalize SHALL post one sales bundle per split group
For each planned split group, the system SHALL create exactly one `sale`, one payment allocation record, and associated dispatch records in the same finalize operation.

#### Scenario: Posting two split groups
- **WHEN** finalize is executed for a checkout with two split groups
- **THEN** two sales bundles are created and linked to the same checkout

### Requirement: Split posting MUST reconcile totals exactly
The system MUST ensure the sum of split-group `subtotal`, `tax_total`, `grand_total`, and `paid_total` equals the corresponding checkout totals using minor-unit-safe arithmetic.

#### Scenario: Totals reconciliation after split posting
- **WHEN** finalize completes with split posting enabled
- **THEN** the aggregate totals from all split groups exactly equal the checkout totals

### Requirement: Tax fallback SHALL be applied for split tax bucket resolution
When a taxable line has no resolved tax from source context, the system SHALL apply fallback policy in order: default tax first, otherwise latest active tax.

#### Scenario: Tax fallback on taxable line without explicit tax
- **WHEN** a taxable line is planned and no source tax is resolvable
- **THEN** the planner assigns tax bucket from default tax or latest active tax according to policy order

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


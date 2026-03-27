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
For each planned split group, the system SHALL create exactly one `sale`, one payment allocation record, and associated dispatch records in the same finalize operation, and SHALL post that bundle using the group `source_setting_id` as owner context.

**CHANGE SUMMARY**: Multi-stage payment flow alters payment submission from a single batch request to individual per-stage submissions. Each payment stage is committed and recorded independently during the checkout flow, before the final finalize call. The finalize operation now processes a pre-computed list of committed payments (from session state) rather than payments submitted inline.

#### Scenario: Posting split groups with pre-committed payments
- **WHEN** finalize is executed for a checkout where payments have been committed across multiple stages (e.g., BRI 1M, BNI 1M, CASH 950k) and stored in session
- **THEN** two split groups are created (if multi-source), finalize receives the pre-committed payment list, and each sale bundle is linked to the committed payments in order
- **AND** no payment re-posting occurs; finalize uses the pre-committed amounts directly

#### Scenario: Posting two split groups under different source owners (unchanged core behavior)
- **WHEN** finalize is executed for a checkout with two split groups owned by different `source_setting_id` values
- **THEN** two sales bundles are created and linked to the same checkout
- **AND** each created sale `setting_id` MUST equal its group `source_setting_id`
- **AND** inventory transactions created for each group line MUST use the same owner setting as that group.

#### Scenario: Owner-specific numbering follows source setting (unchanged core behavior)
- **WHEN** finalize posts split groups across multiple source settings
- **THEN** each sale reference MUST be generated from the owning group setting sequence/prefix rules
- **AND** no sale in the checkout MAY use another setting's numbering sequence.

### Requirement: Split posting MUST reconcile totals exactly
The system MUST ensure the sum of split-group `subtotal`, `tax_total`, `grand_total`, and `paid_total` equals the corresponding checkout totals using minor-unit-safe arithmetic. **When multi-stage payments are used, `paid_total` must equal the sum of all committed payment stages, NOT inline payment inputs.**

#### Scenario: Totals reconciliation after split posting with staged payments (new scenario)
- **WHEN** finalize completes with split posting enabled and checkout has pre-committed staged payments (remainder = 0)
- **THEN** the aggregate totals from all split groups exactly equal the checkout totals
- **AND** `paid_total` of the sale equals the sum of all committed payment stages from session state

#### Scenario: Totals reconciliation after split posting (unchanged core behavior)
- **WHEN** finalize completes with split posting enabled
- **THEN** the aggregate totals from all split groups exactly equal the checkout totals

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

### Requirement: Posted tax persistence SHALL remain consistent with planned source-owner tax policy
Finalize posting SHALL persist tax-bearing fields using the same source-owner tax policy resolved by pre-check and split planning, and MUST NOT re-derive serial tax behavior using a different heuristic.

#### Scenario: Mixed-owner checkout persists tax per owner policy
- **WHEN** a checkout contains chunks from both PKP and non-PKP source owners for the same product line
- **THEN** persisted `sale_details.product_tax_amount` and `dispatch_details.tax_id` MUST reflect only PKP-owned taxable chunks
- **AND** non-PKP owned chunks MUST be persisted as non-tax chunks.


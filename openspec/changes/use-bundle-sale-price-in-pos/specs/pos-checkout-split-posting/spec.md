## MODIFIED Requirements

### Requirement: Checkout split groups SHALL be derived by source and tax bucket
The system SHALL derive split groups for finalized POS checkout lines using key `source_setting_id + source_location_id + tax_bucket` before posting any sale documents. For selected bundled POS lines, split group totals SHALL be derived from internal bundle revenue allocation parts: parent residual revenue follows the parent stock source and component allocation revenue follows each component's stock source or stockless owner rule.

#### Scenario: Grouping mixed-source checkout lines
- **WHEN** a checkout contains lines resolved to multiple source setting/location combinations and mixed tax outcomes
- **THEN** the split planner produces one group per unique `source_setting_id + source_location_id + tax_bucket`

#### Scenario: Grouping selected bundle revenue by parent and component owners
- **WHEN** a selected bundled POS line has a parent product sourced from one owner and a bundled component sourced from another owner
- **THEN** split planning SHALL assign the parent residual amount to the parent source group
- **AND** split planning SHALL assign the component allocation amount to the component source group

#### Scenario: Same-owner bundle allocations combine into one owner group
- **WHEN** a selected bundled POS line has a parent product and bundled component sourced from the same source setting, location, and tax bucket
- **THEN** split planning SHALL include both parent residual and component allocation revenue in that same split group total

### Requirement: Split posting MUST reconcile totals exactly
The system MUST ensure the sum of split-group `subtotal`, `tax_total`, `grand_total`, and `paid_total` equals the corresponding checkout totals using minor-unit-safe arithmetic. **When multi-stage payments are used, `paid_total` must equal the sum of all committed payment stages, NOT inline payment inputs.** Selected bundled POS line decomposition MUST also reconcile exactly: parent residual plus component allocation amounts MUST equal the bundled parent row gross amount.

#### Scenario: Totals reconciliation after split posting with staged payments (new scenario)
- **WHEN** finalize completes with split posting enabled and checkout has pre-committed staged payments (remainder = 0)
- **THEN** the aggregate totals from all split groups exactly equal the checkout totals
- **AND** `paid_total` of the sale equals the sum of all committed payment stages from session state

#### Scenario: Totals reconciliation after split posting (unchanged core behavior)
- **WHEN** finalize completes with split posting enabled
- **THEN** the aggregate totals from all split groups exactly equal the checkout totals

#### Scenario: Bundle allocation reconciliation
- **WHEN** split posting finalizes a selected bundled POS line
- **THEN** the sum of parent residual and all component allocation gross amounts SHALL equal the customer-facing bundled row gross amount
- **AND** the sum of all generated split group grand totals SHALL equal the POS checkout grand total

### Requirement: Posted tax persistence SHALL remain consistent with planned source-owner tax policy
Finalize posting SHALL persist tax-bearing fields using the same source-owner tax policy resolved by pre-check and split planning, and MUST NOT re-derive serial tax behavior using a different heuristic. Selected bundled POS allocation parts SHALL use the parent line tax candidate, or the active/default sale tax when the parent line has no tax, and SHALL extract included tax only for PKP source owners.

#### Scenario: Mixed-owner checkout persists tax per owner policy
- **WHEN** a checkout contains chunks from both PKP and non-PKP source owners for the same product line
- **THEN** persisted `sale_details.product_tax_amount` and `dispatch_details.tax_id` MUST reflect only PKP-owned taxable chunks
- **AND** non-PKP owned chunks MUST be persisted as non-tax chunks.

#### Scenario: Bundle allocation uses parent or default tax for PKP source owner
- **WHEN** POS split posting allocates selected bundle revenue to a PKP source owner
- **AND** the selected bundled parent row has a tax candidate from the parent line or active/default sale tax
- **THEN** the generated owner-specific sale detail tax amount SHALL be extracted as included tax from that allocated gross amount
- **AND** the allocation SHALL NOT use bundled component product-specific tax as its tax candidate

#### Scenario: Bundle allocation remains non-tax for non-PKP source owner
- **WHEN** POS split posting allocates selected bundle revenue to a non-PKP source owner
- **THEN** the generated owner-specific sale detail tax amount for that allocation SHALL be zero
- **AND** persisted dispatch tax for that allocation SHALL remain non-tax

### Requirement: Split planning SHALL allocate stockless bundled component revenue to configured non-PKP source
When a selected bundle contains a non-stock-managed component, split planning SHALL allocate that component's revenue to the first configured sales-location source whose source setting is non-PKP, using existing sales-location configuration ordering. If no configured non-PKP source exists, checkout validation SHALL fail rather than silently assigning the component revenue to the terminal setting.

#### Scenario: Stockless component uses first configured non-PKP source
- **WHEN** POS split planning allocates revenue for a selected bundled component with `stock_managed = false`
- **AND** sales-location configuration contains at least one source setting with `is_pkp = false`
- **THEN** the component allocation revenue SHALL be assigned to the first such configured non-PKP source in the existing sales-location ordering

#### Scenario: Stockless component fails without configured non-PKP source
- **WHEN** POS split planning allocates revenue for a selected bundled component with `stock_managed = false`
- **AND** no configured sales-location source setting has `is_pkp = false`
- **THEN** checkout preflight or finalize SHALL fail with an actionable validation error

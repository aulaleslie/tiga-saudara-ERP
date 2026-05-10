# pos-checkout-split-posting Specification

## Purpose
TBD - created by archiving change implement-pos-phase-3-split-posting. Update Purpose after archive.
## Requirements
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

### Requirement: Split planning SHALL preserve serial parent allocations
When split posting plans a stock-managed serial-tracked POS checkout line, the system SHALL provide a usable parent allocation to the grouped posting context for that line. The grouped parent allocation MUST include source location, source setting, allocated quantity, tax bucket usage, tax policy snapshot, and assigned serial information needed for posting.

#### Scenario: Serial parent allocation survives split planning
- **WHEN** split posting is enabled and checkout finalization plans a stock-managed serial-tracked parent line with valid assigned serials
- **THEN** each grouped line for that parent has a non-empty parent allocation under the grouped line index and/or grouped parent allocation key
- **AND** the inline posting adapter does not fail with missing stock allocation for that parent product

#### Scenario: Serial parent split across two source groups
- **WHEN** a stock-managed serial-tracked parent line has assigned serials from two different allowed source location or tax-bucket groups
- **THEN** split planning creates one grouped parent line per source group
- **AND** each grouped parent line receives only the serial allocation and quantity for that group

### Requirement: Split planning SHALL not duplicate bundle child allocations across groups
When split posting plans a bundled POS checkout line, bundle child allocations SHALL be scoped to the grouped parent line quantity. The system MUST NOT copy the original full-cart bundle child allocation into every split group.

#### Scenario: Bundle child allocation follows grouped parent quantity
- **WHEN** a bundled parent line is split into multiple source groups
- **THEN** each group receives child allocations whose total quantity equals that group's parent quantity multiplied by the child quantity-per-bundle
- **AND** the total child allocation across all groups equals the original required child quantity

#### Scenario: Single-source bundled serial checkout keeps child allocation once
- **WHEN** a bundled serial-tracked parent line is planned into one split group
- **THEN** the group receives exactly the bundle child allocation required for that grouped parent quantity
- **AND** no additional duplicate child allocation is attached to another group



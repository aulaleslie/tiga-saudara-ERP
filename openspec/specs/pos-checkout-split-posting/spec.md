# pos-checkout-split-posting Specification

## Purpose
TBD - created by archiving change implement-pos-phase-3-split-posting. Update Purpose after archive.
## Requirements
### Requirement: Checkout split groups SHALL be derived by source and tax bucket
The system SHALL derive owner-specific groups from actual source setting and location while deriving bundled revenue from the POS owner's captured allocation snapshot. For bundled content, tax grouping SHALL make only the POS-owner allocation taxable when the POS owner is PKP; every other source-owner bundle allocation SHALL be non-tax.

#### Scenario: Parent and component revenue follow actual source owners
- **WHEN** a bundled parent and its components are fulfilled by different settings or locations
- **THEN** the parent residual SHALL be assigned to the parent source owner
- **AND** each fixed component allocation SHALL be assigned to its actual component source owner

#### Scenario: Component allocation retains POS-owner price
- **WHEN** a component allocation is assigned to a different source owner
- **THEN** its amount SHALL remain the value captured from the POS owner's bundle snapshot
- **AND** grouping SHALL NOT reprice it from the source owner's product price

#### Scenario: Only POS-owner group is taxable for PKP POS bundle
- **WHEN** the POS transaction owner is PKP and a bundle is split across multiple owners
- **THEN** only the bundle allocation posted to the POS-owner Sales document SHALL use a taxable bucket
- **AND** every other source-owner bundle allocation SHALL use a non-tax bucket regardless of that source owner's PKP or stock-tax state

#### Scenario: Non-PKP POS owner produces non-tax bundle groups
- **WHEN** the POS transaction owner is non-PKP
- **THEN** all owner groups for that bundled row SHALL be non-tax

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
The system MUST use minor-unit-safe arithmetic so parent residual plus fixed component allocations equals the captured customer bundle amount and aggregate owner Sales totals equal the POS checkout total.

#### Scenario: Configured bundle price reconciles
- **WHEN** a bundle uses its configured parent row price
- **THEN** parent residual plus all component allocations SHALL equal that captured row amount
- **AND** all generated owner-group grand totals SHALL equal the checkout grand total

#### Scenario: Overridden bundle price reconciles through parent residual
- **WHEN** the cashier overrides the bundled parent row price
- **THEN** component allocations SHALL remain fixed
- **AND** only the parent residual SHALL change
- **AND** owner-group totals SHALL reconcile to the overridden captured amount

#### Scenario: Quantity and rounding reconcile across owners
- **WHEN** a multi-quantity bundle requires allocation across multiple owner/location groups
- **THEN** component quantities and amounts SHALL be distributed without losing or duplicating minor units
- **AND** aggregate quantities and money SHALL equal the captured checkout values exactly

### Requirement: Tax fallback SHALL be applied for split tax bucket resolution
For POS checkout split planning, the system SHALL resolve the effective split tax bucket from source owner policy and tax evidence in precedence order: explicit POS line tax, product or product-price sale tax, serial-derived tax for serial-assigned lines, allocation or stock tax, and finally fallback policy (default tax first, otherwise latest active tax). Tax applicability SHALL be gated solely by the source owner's PKP status: when the source owner setting is PKP (`is_pkp=true`), the effective tax bucket MUST be `TAX:<tax_id>` using fallback tax resolution when needed; when the source owner setting is non-PKP (`is_pkp=false`), the effective tax bucket MUST be `NON_TAX` regardless of candidate tax evidence. Which physical stock bucket (`quantity_tax` vs `quantity_non_tax`) an allocation consumed MUST NOT independently make a non-PKP source's allocation taxable, and tax fallback resolution MUST NOT run until the source owner is established as PKP.

Each source owner's stock bucket usage MUST itself stay compatible with its PKP status: a PKP source SHALL only allocate from `quantity_tax`, and a non-PKP source SHALL only allocate from `quantity_non_tax`. When a source's only available stock sits in the bucket incompatible with its own PKP status, allocation from that source MUST fail as insufficient stock (an actionable validation error) rather than silently consuming the incompatible bucket or silently applying fallback tax.

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

#### Scenario: Non-PKP source with only quantity_tax stock is rejected, not silently taxed or non-taxed
- **WHEN** a non-serial POS line is sourced from a non-PKP owner whose only available physical stock at that source sits in `quantity_tax`
- **THEN** the resolver MUST NOT allocate from that source's `quantity_tax` bucket
- **AND** checkout MUST fail with an actionable `STOCK_UNAVAILABLE` validation error instead of posting a non-tax or fallback-taxed allocation

#### Scenario: PKP source with only quantity_non_tax stock is rejected, not silently allocated
- **WHEN** a non-serial POS line is sourced from a PKP owner whose only available physical stock at that source sits in `quantity_non_tax`
- **THEN** the resolver MUST NOT allocate from that source's `quantity_non_tax` bucket
- **AND** checkout MUST fail with an actionable `STOCK_UNAVAILABLE` validation error instead of posting a non-taxable allocation from PKP-owned stock

#### Scenario: PKP source consuming its own quantity_tax stock without explicit tax uses fallback tax
- **WHEN** a POS allocation is sourced from a PKP owner and consumes that owner's `quantity_tax` bucket with no explicit line tax, no product sale tax, and no stock tax
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
Finalize SHALL persist included tax only on the bundled allocation belonging to the PKP POS transaction owner. Source-owner component Sales documents SHALL remain non-tax, and the customer tax summary SHALL equal tax extracted from the POS-owner taxable allocation rather than the full bundle price.

#### Scenario: Canonical three-owner bundle taxes parent owner only
- **WHEN** a PKP Setting 1 POS sells a `5,550,000` bundle with Setting 1 parent residual `5,475,000`, Setting 2 component allocation `50,000`, and Setting 3 component allocation `25,000`
- **THEN** tax SHALL be extracted only from `5,475,000`
- **AND** the Setting 2 and Setting 3 Sales documents SHALL persist zero tax
- **AND** all three Sales totals SHALL still reconcile to `5,550,000`

#### Scenario: Receipt tax reconciles to taxable internal allocation
- **WHEN** a split bundle receipt displays the full parent price and zero/free components
- **THEN** its tax summary SHALL equal the tax persisted for the POS-owner allocation
- **AND** it SHALL NOT extract tax from non-tax source-owner allocations

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
When split posting plans a stock-managed serial-tracked POS checkout line, the system SHALL provide a usable parent allocation to the grouped posting context for that line. The grouped parent allocation MUST include source location, source setting, allocated quantity, tax bucket usage, tax policy snapshot, and assigned serial information needed for posting. When the line is a bundle line, this preservation requirement SHALL also apply independently to each serial-required bundle component, so each component's assigned serials are carried into its grouped child allocation.

#### Scenario: Serial parent allocation survives split planning
- **WHEN** split posting is enabled and checkout finalization plans a stock-managed serial-tracked parent line with valid assigned serials
- **THEN** each grouped line for that parent has a non-empty parent allocation under the grouped line index and/or grouped parent allocation key
- **AND** the inline posting adapter does not fail with missing stock allocation for that parent product

#### Scenario: Serial parent split across two source groups
- **WHEN** a stock-managed serial-tracked parent line has assigned serials from two different allowed source location or tax-bucket groups
- **THEN** split planning creates one grouped parent line per source group
- **AND** each grouped parent line receives only the serial allocation and quantity for that group

#### Scenario: Serial-required bundle component allocation survives split planning
- **WHEN** split posting plans a bundle line containing a serial-required component with valid assigned serials
- **THEN** the grouped child allocation for that component MUST include the component's assigned serial information
- **AND** the posting adapter does not fail with missing stock allocation for that component.

#### Scenario: Serial-required bundle component split across two source groups
- **WHEN** a serial-required bundle component has assigned serials sourced from two different owner groups
- **THEN** split planning creates grouped child allocations per source group for that component
- **AND** each grouped child allocation receives only the serial subset and quantity belonging to that group.

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

### Requirement: Split posting SHALL preserve component allocation breakdown
Finalize SHALL persist each fulfilled bundle component's exact planner allocation on its `SaleBundleItem` while preserving the enclosing Sale detail and Sale header as the authoritative owner-group commercial total. Component allocation rows MUST NOT be added to their enclosing detail when calculating revenue, payments, tax summaries, or reports.

#### Scenario: Component-only owner group persists allocation identity
- **WHEN** a source-owner split group fulfills a `25000` component but no parent stock
- **THEN** its logical parent Sale detail SHALL persist zero quantity, zero parent unit price, and a `25000` owner-group subtotal
- **AND** the fulfilled component row SHALL persist quantity, price, and subtotal representing the same `25000` nested allocation
- **AND** the Sale header and payment allocation SHALL recognize `25000` exactly once

#### Scenario: POS-owner group tax excludes other owners
- **WHEN** a PKP POS owner receives an `85000` parent allocation and another source owner receives a `25000` component allocation from a `110000` bundle
- **THEN** included tax SHALL be extracted only from the POS-owner's `85000` allocation
- **AND** the other source-owner Sale and component allocation SHALL remain non-tax
- **AND** persisting the component subtotal SHALL NOT create an additional tax contribution

#### Scenario: Finalize replay does not duplicate allocation
- **WHEN** a successfully finalized split bundle checkout is replayed using the same idempotency key
- **THEN** the stored response SHALL be returned without creating duplicate Sales, Sale details, bundle items, payments, dispatches, inventory movements, or cost snapshots
- **AND** owner and checkout totals SHALL remain unchanged

#### Scenario: Failed owner group leaves no partial allocation
- **WHEN** any owner group fails validation or posting during split bundle finalization
- **THEN** the checkout SHALL roll back all owner Sales and component allocation persistence
- **AND** no payment, dispatch, inventory, or HPP mutation from that attempt SHALL remain

### Requirement: Bundle revenue consumers SHALL avoid nested double-counting
Internal consumers that read both `sale_details` and `sale_bundle_items` SHALL treat Sale/SaleDetail totals as authoritative revenue and component subtotals as allocation identity only. Component HPP snapshots remain independently authoritative for physical component cost and MUST NOT be confused with component revenue allocations.

#### Scenario: Revenue report sees component allocation once
- **WHEN** an owner Sale detail includes a component allocation also persisted on an attached `SaleBundleItem`
- **THEN** revenue and gross sales reporting SHALL recognize the Sale detail amount once
- **AND** it SHALL NOT add the component subtotal again

#### Scenario: HPP remains independent from component allocation
- **WHEN** a fulfilled component has both a revenue allocation and an immutable HPP snapshot
- **THEN** profitability SHALL use the component cost snapshot for HPP
- **AND** it SHALL NOT treat component price, subtotal, or informational price as cost

### Requirement: POS dispatch details persist authoritative inventory classification
POS checkout posting SHALL explicitly persist `is_inventory_managed` on every generated DispatchDetail. A row that performs stock or serial mutation SHALL persist `true`, while an audit-only service or non-stock row SHALL persist `false`, including bundle parents and components in inline and split posting paths.

#### Scenario: Stock-managed bundle allocation is posted
- **WHEN** POS posting fulfills a bundle parent or component from an inventory allocation
- **THEN** its DispatchDetail SHALL persist `is_inventory_managed = true`
- **AND** the row's quantity, source location, product, bundle, tax, and serial evidence SHALL match the performed physical movement

#### Scenario: Non-stock bundle content is acknowledged
- **WHEN** POS posting creates an audit-only DispatchDetail for a service or non-stock bundle parent or component
- **THEN** its DispatchDetail SHALL persist `is_inventory_managed = false`
- **AND** no stock, serial, or inventory Transaction mutation SHALL occur for that row

#### Scenario: Split bundle posting produces multiple owner groups
- **WHEN** a bundle is fulfilled across multiple POS split groups
- **THEN** each generated DispatchDetail SHALL carry the classification of its own physical or audit-only posting path
- **AND** classification SHALL NOT be copied from an unrelated parent or sibling group


## ADDED Requirements

### Requirement: POS transaction and fulfillment ownership SHALL remain distinct
The system SHALL treat the current setting of the active POS session as the POS transaction owner while deriving every Split Sale owner from the setting that owns its authoritative fulfillment location.

#### Scenario: Foreign location creates foreign-owner Split Sale
- **WHEN** a POS transaction owned by Setting A allocates a quantity from a configured location whose `setting_id` is Setting B
- **THEN** the generated Split Sale for that quantity SHALL have `setting_id` equal to Setting B
- **AND** the POS checkout, receipt, payment, and transaction SHALL remain owned by Setting A

#### Scenario: Source setting must agree with source location
- **WHEN** a planned allocation carries a `source_setting_id` that differs from its source location's persisted `setting_id`
- **THEN** finalize SHALL reject the inconsistent ownership context before posting

### Requirement: Stock-managed non-serial fulfillment SHALL follow configured location order
The system SHALL inspect enabled POS sales locations in their exact configured order and consume available stock at each location before moving to the next location, without reordering sources by PKP status.

#### Scenario: Earlier location partially fulfills quantity
- **WHEN** the first configured location can fulfill only part of a stock-managed non-serial quantity
- **THEN** the resolver SHALL allocate that available part to the first location
- **AND** it SHALL allocate the remainder from subsequent configured locations in order

#### Scenario: Earlier location has no available stock
- **WHEN** an earlier configured location has no available stock for the requested product
- **THEN** the resolver SHALL skip it and continue to the next configured location

### Requirement: Multi-owner plans SHALL always use owner-aware posting
The system MUST use owner-specific split posting whenever an authoritative plan spans multiple source settings or belongs solely to a source setting other than the POS transaction owner, regardless of whether the legacy split-posting feature flag is enabled.

#### Scenario: Disabled feature flag does not collapse cross-owner plan
- **WHEN** the split-posting feature flag is disabled
- **AND** fulfillment resolves to more than one source setting
- **THEN** finalize SHALL create owner-specific Split Sales
- **AND** it SHALL NOT collapse foreign stock or non-stock revenue into the terminal setting's Sale

#### Scenario: Terminal-owned plan may remain inline
- **WHEN** all fulfillment groups belong to the POS transaction owner
- **THEN** finalize MAY retain the existing inline posting path

### Requirement: Split group allocation identity SHALL remain isolated
The system SHALL carry only the parent and component quantities, amounts, stock evidence, and serials fulfilled by each split group, using stable cart-line and bundle-role identity so identical SKUs cannot leak across roles or groups.

#### Scenario: Component-only owner receives no parent movement
- **WHEN** an owner fulfills a bundle component but no parent quantity
- **THEN** its group SHALL persist the component allocation with a zero-quantity bookkeeping parent context
- **AND** it SHALL create no parent stock or serial movement

#### Scenario: Same SKU has multiple roles
- **WHEN** the same product appears as a bundle parent, bundle component, and standalone POS line
- **THEN** each role's quantities and allocations SHALL remain attached to its originating cart line and role
- **AND** no group SHALL copy, omit, or double-post another role's allocation

#### Scenario: Parent and component shares coexist in one group
- **WHEN** one source location fulfills only part of the parent quantity and all or part of a component quantity
- **THEN** its Split Sale SHALL contain exactly those parent and component shares
- **AND** aggregate shares across all groups SHALL equal the original required quantities and captured amounts

### Requirement: Split posting SHALL be atomic across owner groups
The system MUST post every owner group in one atomic operation so failure in any group leaves no partial Sales, dispatch, stock, serial, payment, or checkout-mapping side effects.

#### Scenario: Later owner group fails
- **WHEN** an exception occurs after an earlier owner group has begun posting
- **THEN** all posting side effects from every group SHALL be rolled back
- **AND** the checkout SHALL be recorded as failed only after the posting transaction rolls back

### Requirement: Customer receipt SHALL hide owner-split internals
The system SHALL reconstruct one customer-facing POS line from the captured transaction and complete persisted composition while keeping internal owner allocations and bookkeeping rows hidden.

#### Scenario: Split bundle receipt remains one commercial line
- **WHEN** a bundle is fulfilled through multiple owner Sales
- **THEN** the receipt SHALL show the full captured customer price on the parent and zero/free components
- **AND** it SHALL show every component once without displaying owner allocation prices or zero-quantity bookkeeping parents

## REMOVED Requirements

### Requirement: Split planning SHALL allocate stockless bundled component revenue to configured non-PKP source
**Reason**: Ownership now follows the first enabled configured location; PKP status no longer changes fulfillment ownership.

**Migration**: Use the replacement requirement below, deriving the Split Sale owner from the first configured source location's persisted `setting_id`.

## ADDED Requirements

### Requirement: Split planning SHALL allocate stockless bundled component revenue to first configured source
When a selected bundle contains a non-stock-managed component, split planning SHALL allocate that component's revenue to the first enabled configured POS sales-location source in exact configuration order. The source location's persisted `setting_id` SHALL own the Split Sale. If no enabled configured source exists, checkout validation SHALL fail rather than silently assigning ownership to the terminal setting.

#### Scenario: Stockless component uses first configured source
- **WHEN** POS split planning processes a selected bundled component with `stock_managed = false`
- **AND** at least one enabled POS sales location is configured
- **THEN** the component allocation SHALL be assigned to the first configured source location
- **AND** the Split Sale owner SHALL equal that location's `setting_id` regardless of PKP status

#### Scenario: Stockless component fails without configured source
- **WHEN** POS split planning processes a selected bundled component with `stock_managed = false`
- **AND** no enabled POS sales location is configured
- **THEN** checkout preflight or finalize SHALL fail with an actionable validation error

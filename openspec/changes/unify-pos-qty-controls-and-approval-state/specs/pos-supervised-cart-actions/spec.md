## ADDED Requirements

### Requirement: Non-Privileged Quantity Controls MUST Use A Consistent Slot-First Layout
For users without direct quantity-reduction permission, each cart row SHALL render quantity controls in a consistent order where the first control slot is reserved for supervised reduction state, followed by quantity input and increment control.

#### Scenario: Non-serial row uses slot-first quantity control structure
- **WHEN** a non-serial cart row is rendered for a user without direct `qty_reduce` permission
- **THEN** the row MUST render controls in the order `[Reduce/Periksa/Lanjutkan slot][qty input][increase]`
- **AND** the left slot MUST render exactly one control state at a time.

#### Scenario: Serial-required row uses the same top control strip
- **WHEN** a serial-required cart row is rendered for a user without direct `qty_reduce` permission
- **THEN** the top control strip MUST use the same order `[Reduce/Periksa/Lanjutkan slot][qty input][increase]`
- **AND** serial-management controls MUST remain available as a secondary line in the same row.

### Requirement: Quantity-Reduction Approval Slot MUST Be Deterministic Without Full Page Refresh
After users request or check supervised quantity reduction, the cart row approval slot SHALL transition to the correct state based on latest approval status without requiring manual browser reload.

#### Scenario: Pending request remains bound to Periksa state
- **WHEN** a user checks approval status and the latest status remains `PENDING`
- **THEN** the left slot MUST continue rendering `Periksa Persetujuan` bound to the active request id.

#### Scenario: Approved request transitions to proceed state
- **WHEN** a user checks approval status and the latest status is `APPROVED`
- **THEN** the left slot MUST render the approved proceed control with approval token and approved quantity context.

#### Scenario: Rejected or cancelled request resets to reduce-request state
- **WHEN** a user checks approval status and the latest status is `REJECTED` or `CANCELLED`
- **THEN** the row MUST stop rendering follow-up approval controls
- **AND** the left slot MUST render the normal quantity-reduction request control.

### Requirement: Serial And Non-Serial Rows MUST Share One Qty-Approval State Mapping Contract
The POS sell UI SHALL resolve quantity-approval rendering state through one canonical mapping path so equivalent approval metadata produces equivalent control output regardless of row type.

#### Scenario: Equivalent approval metadata produces equivalent slot state across row types
- **WHEN** serial and non-serial rows receive the same normalized quantity-approval state (`PENDING`, `APPROVED`, `REJECTED`, or `CANCELLED`)
- **THEN** both rows MUST render the same slot state semantics for quantity reduction.

#### Scenario: Latest server snapshot is used after approval status checks
- **WHEN** a `Periksa Persetujuan` action completes
- **THEN** the next cart render MUST use the latest `cart_snapshot` approval metadata as authoritative state for slot rendering.

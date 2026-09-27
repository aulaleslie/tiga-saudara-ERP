# Spec Delta

## MODIFIED Requirements

### Requirement: Non-serialized allocations exactly cover the requested quantity
The approval workspace SHALL allow incomplete non-serialized allocation rows to contain zero quantity, an unset source or destination, totals that do not yet equal the request, temporarily repeated sources, a source equal to destination, or quantities not currently fulfillable by live stock. Simpan Progres SHALL retain each explicit incomplete row and its order without treating it as an executable plan. For Setujui dan Kirim, the approver SHALL use valid searchable source locations with eligible stock for the product and condition across all businesses; each allocation MUST have a positive whole-base-unit quantity and a destination distinct from its source, combined allocations for each product MUST exactly equal its requested quantity, and repeated sources MUST pass aggregate locked stock validation.

#### Scenario: Save zero and incomplete allocations
- **WHEN** an approver saves progress containing zero quantities, missing sources or destinations, mismatched totals, self-directed draft rows, or quantities beyond current stock
- **THEN** the workspace state is retained for later editing without inventory reservation, movement, approval, or final-plan validation failure

#### Scenario: Split ten units across sources
- **WHEN** final approval assigns six units from A and four from B with valid distinct destinations
- **THEN** the ten-unit request is fully allocated

#### Scenario: Repeated source would overdraw stock
- **WHEN** final approval attempts two allocations drawing six units each from one source with only ten eligible units
- **THEN** approval fails even if each allocation independently fits

#### Scenario: Allocation is incomplete or self-directed
- **WHEN** Setujui dan Kirim encounters a zero quantity, short or excessive total, unset route, source equal to destination, or insufficient live stock
- **THEN** final approval is blocked with allocation-specific guidance and no dispatch effects

#### Scenario: Long location labels stay within the workspace
- **WHEN** source or destination labels are long, or the viewport is narrow
- **THEN** allocation tables keep destination, quantity, and remove controls reachable within the card, selected labels are truncated while the open searchable list shows full labels, narrow screens scroll inside the table rather than the page, and source stock is shown below the selected source (with the tax split only for approvers who may view system stock)

### Requirement: Approval configuration is manually resumable and revision-bound
Simpan Progres SHALL persist the approver's incomplete workspace state with actor, time, manifest revision, configuration revision, and row order without applying final completeness, positive-quantity, quantity-total, route-distinctness, serial-fulfillment, or live-stock validation and without reserving or moving stock. It SHALL still enforce authentication, approval permission, matching transfer/request revision, supported field shape and scalar types, referential safety for any supplied product/location identities, and optimistic concurrency. Authorized approvers SHALL resume the saved configuration exactly. Concurrent saves MUST reject stale revisions rather than overwrite another approver's work.

#### Scenario: Resume incomplete approval
- **WHEN** an approver saves any structurally valid partial workspace and later returns
- **THEN** zero quantities, missing choices, partial totals, and other incomplete entries are restored while the document remains pending without inventory reservations

#### Scenario: Save progress does not execute business validation
- **WHEN** current stock, route completeness, totals, or serial fulfillment would prevent final approval
- **THEN** Simpan Progres retains the workspace state and defers those checks to Setujui dan Kirim

#### Scenario: Reject unsafe or malformed progress payload
- **WHEN** a save-progress request lacks permission, targets another document or revision, supplies malformed field types, or references nonexistent identities that cannot be stored safely
- **THEN** the system rejects the save without overwriting the existing configuration

#### Scenario: Concurrent approvers save
- **WHEN** another save advances configuration revision before a stale save arrives
- **THEN** the stale save is rejected and the saved configuration is preserved

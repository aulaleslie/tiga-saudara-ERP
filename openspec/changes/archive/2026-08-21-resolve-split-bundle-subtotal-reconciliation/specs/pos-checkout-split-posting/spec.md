## ADDED Requirements

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

## ADDED Requirements

### Requirement: Return-dispatch preparation respects stock visibility
Return-dispatch action permission SHALL NOT imply stock visibility. A blind preparer SHALL see permitted transfer context, obligated product identities, condition, and only their own observations and confirmation state, while protected obligation quantities, received or in-transit totals, destination stock, allocations, expected serials, differences, and capacity values MUST be absent.

#### Scenario: Blind dispatcher opens a return draft
- **WHEN** an authorized destination dispatcher lacks `stockTransfers.view-system-stock`
- **THEN** product identities may guide preparation but no required, returned, in-transit, outstanding, available, allocation, expected-serial, or difference value is included

#### Scenario: Privileged dispatcher opens a return draft
- **WHEN** an otherwise-authorized destination dispatcher has stock visibility
- **THEN** the projection may include exact obligation, active-batch, stock, allocation, serial, and capacity information useful for preparation

#### Scenario: Blind scan fails
- **WHEN** a blind dispatcher scans an ineligible product or serial or exceeds authoritative capacity
- **THEN** browser feedback is neutral and non-quantitative and exposes no protected reason, quantity, serial provenance, or competing batch detail

### Requirement: Return-dispatch approval comparison respects stock visibility
A return-dispatch approver without stock visibility SHALL receive only neutral authoritative success or corrective guidance, while an otherwise-authorized stock-visible approver MAY receive exact obligation-versus-batch, inventory, reservation, serial, allocation, and difference details.

#### Scenario: Blind approver reviews a valid partial batch
- **WHEN** locked comparison and fulfillment checks pass for an approver without stock visibility
- **THEN** the batch may be approved without returning protected quantities, serial expectations, stock, allocations, capacity, or differences

#### Scenario: Blind approver reviews an overcommitted batch
- **WHEN** concurrent reservations leave insufficient obligation capacity
- **THEN** the browser receives neutral corrective guidance and the pending batch remains unapplied

#### Scenario: Privileged approver reviews a batch
- **WHEN** an approver also has stock visibility
- **THEN** the projection may show exact required, returned, active in-transit, proposed, remaining, stock, allocation, serial, and comparison detail

#### Scenario: Crafted request injects protected values
- **WHEN** any preparer or approver supplies client-side stock, obligation capacity, policy, allocation, expected manifest, or comparison values
- **THEN** the system ignores or rejects them and derives every decision from locked authoritative server data

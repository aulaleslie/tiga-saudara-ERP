# Spec Delta

## MODIFIED Requirements

### Requirement: Sold and receipt capacities are enforced atomically
Submission and approval SHALL atomically ensure that each requested quantity does not exceed both the sold source's remaining eligible quantity and each receiving detail's remaining supplier-owned quantity after effective returns, reversals, approved allocations, and other active reservations. Locks SHALL be acquired in a stable order, and over-allocation SHALL be rejected rather than reassigned. Capacity SHALL include active legacy reservations/allocations and approved or finalized non-reversed buyout consumption exactly once. Unsold physical availability SHALL additionally exclude explicitly attributed outstanding sales and incompatible physical reservations; financial receipt residual alone SHALL NOT establish physical availability.

#### Scenario: Two submissions compete for the same sold quantity
- **WHEN** concurrent confirmations attempt to reserve more than the remaining quantity of one sold source
- **THEN** at most the valid remaining quantity SHALL be reserved
- **AND** the losing transaction SHALL make no partial reservation

#### Scenario: Two submissions compete for one receipt pool
- **WHEN** concurrent confirmations attempt to reserve more than one receiving detail's remaining supplier-owned quantity
- **THEN** at most the available receipt quantity SHALL be reserved
- **AND** the system SHALL NOT substitute another receipt lot

#### Scenario: Later return invalidates a pending confirmation
- **WHEN** an effective return reduces capacity after submission but before approval
- **THEN** approval SHALL fail atomically and require the confirmation to be revised

#### Scenario: Legacy confirmation competes with a buyout
- **WHEN** sold-only confirmation submission and buyout approval compete for the same receipt or sold-source balance
- **THEN** shared authoritative capacity SHALL permit at most the available quantity across both workflows
- **AND** the losing transaction SHALL create no partial allocation or claim

#### Scenario: A buyout is finalized or reversed
- **WHEN** an approved buyout is finalized or an eligible finalized buyout is fully reversed
- **THEN** its reservation SHALL become consumed capacity or released capacity respectively without counting both the reservation and its posting

### Requirement: Physically effective pre-billing returns reduce eligibility
The system SHALL deduct a Sales Return detail from allocatable sold quantity only when its parent return has been physically received and is in `AWAITING SETTLEMENT` or `COMPLETED`, including POS Returns executed through linked Sales Returns. Pending, rejected, unreceived, cancelled without execution, or archived-only return requests SHALL NOT reduce eligibility. Return quantities covered by a finalized buyout SHALL retain company ownership and SHALL NOT reopen supplier capacity or invalidate the acquired quantity as a pre-billing return; mixed quantities SHALL be explicitly apportioned. Legacy billed-source behavior SHALL remain unchanged.

#### Scenario: Return is physically received before confirmation
- **WHEN** a return linked to a consignment dispatch detail reaches `AWAITING SETTLEMENT` or `COMPLETED`
- **THEN** its effective base quantity and serial identities SHALL reduce the sold source's allocatable balance

#### Scenario: Return is only requested or rejected
- **WHEN** a linked return remains pending, is rejected, or has not been physically received
- **THEN** it SHALL NOT reduce consignment allocation eligibility

#### Scenario: Return exceeds unallocated balance
- **WHEN** effective return evidence conflicts with existing pending or approved quantities
- **THEN** the affected source SHALL be flagged for reconciliation
- **AND** no new approval SHALL proceed against the conflicting quantity

#### Scenario: Return follows buyout acquisition
- **WHEN** a physically received return contains quantity acquired through a finalized buyout
- **THEN** only its unacquired portion SHALL participate in pre-billing eligibility deductions
- **AND** its acquired portion SHALL retain original supplier consumption and retain company ownership through recorded return lineage and the existing custody/release policy

### Requirement: Reconciliation exposes custody-to-allocation balances
The consignment reconciliation SHALL expose approved received, reversed, sold-from-consignment, physically returned-before-billing, waiting-reserved, approved-allocated, and remaining receipt-pool quantities with filters for setting, supplier, product, location, source transaction, confirmation status, and serial. Totals SHALL derive from immutable source and allocation events rather than mutable available-balance counters. Supported inventory-managed bundle movements SHALL participate in these totals using their physical DispatchDetail product and quantity. Reconciliation SHALL additionally distinguish non-financial sold receipt attribution, active buyout reservations, acquired sold/unsold quantities, ownership movements, and reversals without treating the same goods as both available consignment and acquired stock.

#### Scenario: Reconciliation includes allocated and remaining quantities
- **WHEN** a supplier receipt pool has sold sources, a waiting confirmation, and an approved confirmation
- **THEN** reconciliation SHALL separately show reserved, approved-allocated, and remaining quantities without double counting

#### Scenario: Supported bundle movement is viewed
- **WHEN** a stock-managed bundle parent or component was dispatched from a consignment location
- **THEN** reconciliation SHALL show its sold source as eligible or allocated rather than as an unsupported bundle blocker

#### Scenario: Standard-only activity is viewed
- **WHEN** Sales and POS activity uses only standard locations
- **THEN** consignment allocation totals SHALL remain unchanged

#### Scenario: Unsupported or ambiguous evidence exists
- **WHEN** discovery encounters missing lineage, non-stock content, unreconstructable historical quantity, unknown hash version, or conflicting return/allocation evidence
- **THEN** reconciliation SHALL expose a blocker with its source reference rather than silently omitting or allocating it

#### Scenario: Sold attribution is not supplier billing
- **WHEN** staff attribute pooled sales to supplier receipts before preparing an unsold buyout
- **THEN** reconciliation SHALL show that attribution separately from payable creation and financial allocation
- **AND** those sold quantities SHALL no longer appear as physically unsold for the attributed receipts

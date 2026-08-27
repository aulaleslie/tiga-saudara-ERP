## ADDED Requirements

### Requirement: Approved dispatch evidence governs consignment sale eligibility
The system SHALL identify billable consignment sale quantities only from approved dispatch details whose persisted source location belongs to the active setting and is classified as consignment. Ordinary Sales and POS-generated Sales SHALL use the same dispatch-detail authority, and the system SHALL NOT rerun current stock selection or POS location priority during discovery.

#### Scenario: Ordinary sale is dispatched from a consignment location
- **WHEN** an approved ordinary Sales dispatch detail records a supported stock-managed product and a consignment source location
- **THEN** the dispatched base quantity SHALL become eligible for consignment allocation

#### Scenario: POS sale uses mixed source locations
- **WHEN** a posted POS checkout produces approved dispatch details for the same product from standard and consignment locations
- **THEN** only quantities persisted on dispatch details from consignment locations SHALL become eligible
- **AND** current POS location priority SHALL NOT be recalculated

#### Scenario: Dispatch is pending, rejected, standard, or unsupported
- **WHEN** a dispatch is not approved, uses a standard location, or represents a bundle, service, or non-stock product
- **THEN** it SHALL NOT become allocatable
- **AND** an unsupported consignment dispatch SHALL remain visible as a reconciliation blocker where applicable

### Requirement: Sold-source evidence is immutable and idempotent
The system SHALL create at most one immutable consignment sold-source record per eligible dispatch detail and SHALL snapshot its original product, setting, sale, optional POS checkout, source location, base quantity, dispatch time, tax context, serial identities, source references, and canonical source hash. Discovery SHALL support future and historical eligible dispatches without rewriting an existing sold source.

#### Scenario: Eligible dispatch is discovered repeatedly
- **WHEN** discovery processes the same eligible dispatch detail more than once
- **THEN** exactly one sold-source record SHALL exist
- **AND** its immutable source snapshot SHALL remain unchanged

#### Scenario: Historical POS return reduced dispatch quantity
- **WHEN** discovery finds an eligible POS dispatch whose quantity was reduced by an executed cash return before sold-source capture
- **THEN** the system SHALL reconstruct original sold quantity from authoritative dispatch and executed return evidence
- **AND** ambiguous evidence SHALL block allocation instead of being guessed

#### Scenario: Source changes after capture
- **WHEN** mutable dispatch, location configuration, serial state, or checkout data changes after sold-source capture
- **THEN** the original sold-source evidence SHALL remain unchanged
- **AND** lifecycle revalidation SHALL report any incompatible change

### Requirement: Serialized ownership resolves from approved receipt lineage
For every serialized sold unit, the system SHALL resolve exactly one approved, non-reversed Consignment Receiving detail for the same product and source location using immutable receiving pivots and serial history. The resolved supplier and receipt lot SHALL be non-editable, and a serialized unit SHALL have at most one active or approved allocation claim.

#### Scenario: Sold serial has valid consignment lineage
- **WHEN** a sold serial maps unambiguously to an approved consignment receiving detail matching its sold product and location
- **THEN** the system SHALL display the resolved supplier, receipt, cost, and tax snapshots read-only
- **AND** the user SHALL NOT redirect it to another supplier or receipt lot

#### Scenario: Serialized lineage is missing or ambiguous
- **WHEN** a sold serial has no eligible receipt lineage, multiple conflicting lineages, a reversed source, or a product/location mismatch
- **THEN** allocation SHALL be blocked with actionable lineage evidence

#### Scenario: Returned serial no longer has a current dispatch pointer
- **WHEN** an effective return cleared a serial's current dispatch pointer
- **THEN** the system SHALL retain its original sold and supplier identity through immutable sold-source, receiving-pivot, and history evidence

### Requirement: Non-serialized quantities are manually allocated to same-location supplier receipt lots
An authorized user SHALL allocate non-serialized sold base quantity explicitly among approved, non-reversed Consignment Receiving details belonging to the confirmation supplier, product, setting, and exact sold source location. The system SHALL permit multiple receipt lots for that supplier but SHALL NOT automatically substitute, pool across locations, or apply FIFO/LIFO.

#### Scenario: User allocates across eligible receipt lots
- **WHEN** a supplier has multiple eligible receiving-detail lots for the sold product at the source location
- **THEN** the user SHALL be able to distribute the sold quantity across those lots
- **AND** each selected quantity and commercial snapshot SHALL be retained separately

#### Scenario: User selects another location or supplier
- **WHEN** an allocation references a receipt lot from a different supplier, setting, product, or location
- **THEN** validation SHALL reject it without reserving or approving quantity

#### Scenario: Allocation does not cover the selected sold quantity
- **WHEN** receipt-lot allocations do not sum exactly to the sold base quantity selected for confirmation
- **THEN** submission SHALL fail with no reservation change

### Requirement: Sold and receipt capacities are enforced atomically
Submission and approval SHALL atomically ensure that each requested quantity does not exceed both the sold source's remaining eligible quantity and each receiving detail's remaining supplier-owned quantity after effective returns, reversals, approved allocations, and other active reservations. Locks SHALL be acquired in a stable order, and over-allocation SHALL be rejected rather than reassigned.

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

### Requirement: Physically effective pre-billing returns reduce eligibility
The system SHALL deduct a Sales Return detail from allocatable sold quantity only when its parent return has been physically received and is in `AWAITING SETTLEMENT` or `COMPLETED`, including POS Returns executed through linked Sales Returns. Pending, rejected, unreceived, cancelled without execution, or archived-only return requests SHALL NOT reduce eligibility.

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

### Requirement: One-supplier confirmations govern reservation and approval
The system SHALL provide setting-scoped consignment billing confirmations belonging to exactly one supplier with lifecycle `DRAFT`, `WAITING_APPROVAL`, `APPROVED`, or `REJECTED`. Drafts SHALL NOT reserve quantity; successful submission SHALL reserve it; approval SHALL convert reservations into immutable allocations; rejection SHALL release reservations while preserving audit evidence.

#### Scenario: Valid draft is submitted
- **WHEN** an authorized user submits a valid one-supplier confirmation
- **THEN** its status SHALL become `WAITING_APPROVAL`
- **AND** sold-source and receipt-pool quantities SHALL be reserved atomically

#### Scenario: Pending confirmation is approved
- **WHEN** an authorized approver approves a still-valid waiting confirmation
- **THEN** its allocations and commercial/source snapshots SHALL become immutable
- **AND** its reservations SHALL become approved allocations exactly once

#### Scenario: Pending confirmation is rejected
- **WHEN** an authorized approver rejects a waiting confirmation with a required reason
- **THEN** its reservations SHALL be released
- **AND** actor, time, reason, and prior submission evidence SHALL be retained

#### Scenario: Rejected confirmation is revised and resubmitted
- **WHEN** an authorized user revises and submits a rejected confirmation with currently valid quantities
- **THEN** new reservations SHALL be established from authoritative capacity
- **AND** earlier rejection evidence SHALL remain auditable

### Requirement: Confirmation lifecycle is tenant-safe, permissioned, and stale-safe
All discovery, create, edit, submit, approve, reject, and detail actions SHALL enforce dedicated permissions and active setting boundaries in controllers and domain services. Submission and approval SHALL lock and revalidate authoritative dispatch, return, receipt, serial, supplier, product, location, and snapshot evidence inside one database transaction.

#### Scenario: Foreign-setting action is invoked directly
- **WHEN** a user invokes a confirmation or allocation action outside the active accessible setting
- **THEN** the system SHALL deny access without disclosing or mutating the record

#### Scenario: User lacks lifecycle permission
- **WHEN** a user without the relevant permission accesses a menu, page, or action
- **THEN** it SHALL be unavailable or denied

#### Scenario: Source snapshot becomes stale
- **WHEN** authoritative source or eligibility evidence no longer matches the submitted snapshot
- **THEN** submission or approval SHALL fail with actionable blockers
- **AND** no partial lifecycle or reservation change SHALL persist

### Requirement: Allocation approval is financially and operationally inert
Approving a Phase 2 confirmation SHALL record only supplier allocation and audit evidence. It SHALL NOT create or update Purchase, PurchaseDetail, ReceivedNote, payable, PurchasePayment, payment eligibility, inventory quantity, average or last purchase cost, serial status, Sales, POS, dispatch, or return records.

#### Scenario: Confirmation approval succeeds
- **WHEN** a valid waiting confirmation is approved
- **THEN** it SHALL be marked ready for future billing
- **AND** no financial, inventory, serial, sale, checkout, dispatch, or return mutation SHALL occur

#### Scenario: Approval fails on a later line
- **WHEN** any line, serial, receipt pool, or capacity validation fails during approval
- **THEN** every confirmation, reservation, and allocation mutation from that attempt SHALL roll back

### Requirement: Reconciliation exposes custody-to-allocation balances
The consignment reconciliation SHALL expose approved received, reversed, sold-from-consignment, physically returned-before-billing, waiting-reserved, approved-allocated, and remaining receipt-pool quantities with filters for setting, supplier, product, location, source transaction, confirmation status, and serial. Totals SHALL derive from immutable source and allocation events rather than mutable available-balance counters.

#### Scenario: Reconciliation includes allocated and remaining quantities
- **WHEN** a supplier receipt pool has sold sources, a waiting confirmation, and an approved confirmation
- **THEN** reconciliation SHALL separately show reserved, approved-allocated, and remaining quantities without double counting

#### Scenario: Standard-only activity is viewed
- **WHEN** Sales and POS activity uses only standard locations
- **THEN** consignment allocation totals SHALL remain unchanged

#### Scenario: Unsupported or ambiguous evidence exists
- **WHEN** discovery encounters a bundle, missing lineage, unreconstructable historical quantity, or conflicting return/allocation evidence
- **THEN** reconciliation SHALL expose a blocker with its source reference rather than silently omitting or allocating it

## ADDED Requirements

### Requirement: Replacement conserves customer commercial value
A completed cross-owner replacement SHALL move only its proven commercial charge from the current source owner to the replacement owner. Source and replacement sale headers SHALL agree with their authoritative remaining charges. The aggregate customer charge SHALL be unchanged by replacement alone; legitimate price edits and completed cash returns SHALL retain their separate effects.

#### Scenario: Source sale contains another product
- **WHEN** a Rp2,225,000 printer is replaced across owners from a Rp2,270,000 sale also containing Rp45,000 paper
- **THEN** the source sale retains Rp45,000 and the replacement sale receives Rp2,225,000
- **AND** an unrelated Rp330,000 sale is unchanged and aggregate charges remain Rp2,600,000

#### Scenario: Partial and repeated replacements
- **WHEN** part of a purchased quantity is replaced and its replacement is later replaced across owners again
- **THEN** each execution moves only the affected current charge from its current owner
- **AND** unrelated quantities and charges remain unchanged without cumulative duplication

#### Scenario: Same-owner or informational replacement
- **WHEN** a replacement retains the same owner or is an existing non-serial note-only or informational component action
- **THEN** its existing commercial behavior remains intact without creating an owner-value transfer

### Requirement: Owner payment transfers preserve customer collection evidence
Replacement SHALL preserve aggregate effective paid and outstanding debt and MUST NOT create a new customer collection or refund. Owner payment allocation adjustments SHALL retain links to original customer payments, payment methods, and replacement events. Original collection facts SHALL remain auditable; settlement, receipts, and collection reports SHALL distinguish allocation transfers from fresh money.

#### Scenario: Fully paid cross-owner replacement
- **WHEN** a fully paid Rp2,225,000 item is replaced across owners
- **THEN** its payment value is allocated to the replacement sale once and removed from the source sale's effective allocation
- **AND** aggregate paid and customer cash collected remain unchanged with zero additional refund

#### Scenario: Unpaid or partially paid source
- **WHEN** a replacement moves value from an unpaid or partially paid sale
- **THEN** transferred effective paid is backed by existing payment evidence and does not exceed available paid value or the transferred charge
- **AND** aggregate effective paid and debt remain unchanged
- **AND** unsupported payment allocation is refused before any execution effects persist

#### Scenario: Collection reporting after replacement
- **WHEN** a report aggregates customer collections after an owner payment transfer
- **THEN** the original collection is counted once and the transfer is identifiable as an allocation adjustment
- **AND** method totals reconcile to the customer's effective retained paid amount

### Requirement: Replacement accounting commits as one auditable event
Replacement execution SHALL atomically commit source charge correction, owner payment allocation, replacement sale, physical effects, and audit evidence. Each effective transfer SHALL have unique return-detail lineage. Repeated execution MUST NOT move value twice; a failed validation or write SHALL roll back every effect of that execution.

#### Scenario: Failure after replacement sale creation
- **WHEN** payment allocation, dispatch, or final reconciliation fails during replacement execution
- **THEN** source and replacement accounting, stock, serial, and audit changes roll back together

#### Scenario: Duplicate execution request
- **WHEN** the same completed return detail is executed again
- **THEN** it creates no second sale, payment allocation, stock movement, or serial transition

### Requirement: Refunds reduce only the specific backing allocation chain
A later cash refund of an overpaid replacement sale SHALL trim only incoming owner payment transfers backed by active payments. It SHALL reduce the refunded transfer and each predecessor hop by the same amount and reduce only the specific root payment backing the chain, leaving sibling payments, methods, and other owners' allocations untouched.

#### Scenario: Chained refund
- **WHEN** a Rp500,000 refund is applied to the final owner C of an A to B to C replacement chain
- **THEN** effective paid is A Rp45,000, B Rp0, and C Rp1,725,000

#### Scenario: Sibling payment preserved
- **WHEN** a refund reduces B's chain while another payment on the same source sale backs an unrelated transfer to owner D
- **THEN** only the payment backing B's chain is reduced
- **AND** the sibling payment remains active, keeps its method, and D retains Rp100,000

#### Scenario: Invalidated allocation ignored
- **WHEN** a replacement sale has incoming transfers backed by both active and invalidated payments
- **THEN** a refund trims only the active-backed transfers without error

### Requirement: Transfer reductions preserve reversal evidence
Every reduction or removal of an owner payment transfer SHALL first record append-only reversal evidence containing the transfer snapshot, amounts before and after, actor, and sale return. A transfer MUST NOT be removed without such a record.

#### Scenario: Full reduction
- **WHEN** a refund reduces a transfer to zero
- **THEN** the transfer is removed only after its snapshot and amounts are recorded
- **AND** each reduced predecessor hop has its own reversal record

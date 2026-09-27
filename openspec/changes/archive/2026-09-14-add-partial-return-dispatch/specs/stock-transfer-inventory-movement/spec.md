## ADDED Requirements

### Requirement: Return-dispatch approval deducts destination inventory atomically
Approval of a return-dispatch batch MUST lock the transfer, route policy, obligations, active reservations, products, destination stock, selected serials, and relevant provenance in stable order before deducting the approved quantity from the inventory classification established at forward receipt.

#### Scenario: Non-PKP destination returns good stock
- **WHEN** an eligible good-condition batch is approved from a destination classified `NON_TAX`
- **THEN** the exact quantity is deducted from good non-tax stock and its before/after snapshot and transfer transaction reference are retained

#### Scenario: PKP destination returns broken stock
- **WHEN** an eligible broken-condition batch is approved from a destination classified `TAX`
- **THEN** the exact quantity is deducted from broken taxed stock under the snapshotted tax provenance

#### Scenario: Destination stock is insufficient
- **WHEN** locked eligible destination inventory cannot cover the approving batch
- **THEN** approval fails without stock, reservation, serial, custody, history, movement, or header effects

#### Scenario: Global total would underflow
- **WHEN** deducting an otherwise selected batch would make an authoritative global product total negative
- **THEN** approval fails atomically rather than clamping the total

### Requirement: Approved return serials enter exclusive return-leg custody
Approval of a serialized return-dispatch batch SHALL create exclusive active transit custody for each selected live serial, preserve its destination location until receipt, and make it unavailable to every competing operational inventory flow.

#### Scenario: Approve substitute return serial
- **WHEN** an eligible substitute serial passes locked approval validation
- **THEN** a return-leg claim links the live serial, approved movement serial, batch, and transfer while the live location remains the transfer destination

#### Scenario: Serial already has active custody
- **WHEN** a selected serial is already claimed by another forward or return movement
- **THEN** return-dispatch approval rejects atomically

#### Scenario: Approval fails after custody processing begins
- **WHEN** any later inventory, reservation, history, or movement invariant fails
- **THEN** all newly created claims and custody changes roll back with the approval

### Requirement: Return-dispatch effects are idempotent and provenance-complete
The system SHALL apply each approved batch once and SHALL persist exact decimal bucket allocation, product and stock snapshots, canonical transaction identity, serial tax/custody snapshots, source receipt, obligation reservation, actor, timestamp, and history needed to audit and later receive that batch.

#### Scenario: Approval is retried
- **WHEN** an already committed batch approval is repeated in the same action scope
- **THEN** no destination deduction, transaction, reservation, serial claim, or history row is duplicated

#### Scenario: Provenance persistence fails
- **WHEN** any required allocation, transaction, reservation, serial, or history provenance cannot be stored
- **THEN** the entire approval rolls back and the batch remains unapproved

# sales-return-custody Specification

## Purpose
Tracks physical custody of standard returned goods independently of sellable stock and financial settlement, preserving auditable quantity and serial conservation.

## Requirements

### Requirement: Standard receiving creates unavailable custody
Future standard receiving SHALL record returned goods in held custody by source detail, product, owner, location, tax identity, quantity, and serial occurrence. Receiving MUST NOT add held goods to sellable stock or activate held serials for sale. Custody MUST remain visible even after financial completion or source-sale archival.

#### Scenario: Returned goods received
- **WHEN** an approved standard return receives two units including a tracked serial
- **THEN** held custody increases by two units and receiving evidence identifies the actor and time
- **AND** sellable quantity is unchanged and the serial cannot be sold, replaced from available stock, transferred, or independently adjusted out of custody

#### Scenario: Receiving retried
- **WHEN** receiving is retried for the same physical occurrence
- **THEN** custody and receiving history are recorded once

### Requirement: Inspection controls release to sellable stock
Release SHALL require a dedicated permission and an inspection recording actor, time, reason or notes, quantity or serial identities, owner and location. Only remaining held goods eligible for store retention MUST be released. Release MUST transfer custody into the correct sellable owner/tax stock bucket atomically and MUST NOT duplicate physical receiving.

#### Scenario: Refund settled but goods not inspected
- **WHEN** a cash-return settlement completes
- **THEN** returned goods remain unavailable until an explicit authorized inspection release

#### Scenario: Replacement dispatched
- **WHEN** a replacement is dispatched from separate sellable stock
- **THEN** the returned original remains held until inspection releases it

#### Scenario: Partial non-serial inspection
- **WHEN** an inspector releases one of three eligible held units
- **THEN** sellable stock increases by one and held custody remains two

#### Scenario: Missing permission or exhausted custody
- **WHEN** release lacks permission or targets already released or dispatched goods
- **THEN** it is rejected without stock, serial, or custody changes

### Requirement: Original-goods dispatch consumes custody
Approved dispatch for repair of the original goods or cannot process SHALL consume the exact held goods and mark their serials out of warehouse custody. It MUST NOT decrement unrelated sellable stock or leave phantom warehouse units. Customer-bound original goods MUST NOT simultaneously be released for sale.

#### Scenario: Same serial returned to customer
- **WHEN** dispatch approval sends the original received serial after repair or inability to process
- **THEN** its held balance is consumed once and its outbound serial lineage and movement evidence are recorded
- **AND** no sellable stock is added or deducted for that held unit

#### Scenario: Original goods reserved for customer dispatch
- **WHEN** an inspector attempts sellable release of goods committed to original-goods dispatch
- **THEN** release is blocked

### Requirement: Replacement dispatch preserves both stock identities
Standard replacement dispatch SHALL consume the selected replacement's available stock from the authorized location and owner bucket while preserving the returned original's held balance. Serial and quantity eligibility MUST be revalidated at approval; insufficient stock MUST roll back the entire action.

#### Scenario: Different serial replacement
- **WHEN** replacement serial B is approved for returned serial A
- **THEN** B leaves available stock and retains replacement lineage to A
- **AND** A remains held and unavailable until inspection

#### Scenario: Non-serial replacement
- **WHEN** approved standard replacement dispatch sends a non-serial quantity
- **THEN** the selected quantity leaves replacement stock while the original held quantity remains unchanged
- **AND** POS non-serial note-only replacement behavior remains unchanged

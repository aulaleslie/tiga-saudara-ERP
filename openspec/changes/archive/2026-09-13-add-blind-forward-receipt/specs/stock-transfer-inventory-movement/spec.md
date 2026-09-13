## MODIFIED Requirements

### Requirement: Receiving mirrors actual dispatched provenance
For workflow version `2` eligible no-return routes, approved forward receipt SHALL add exactly the approved source dispatch's immutable applied quantities and serials to the destination under recorded tax and broken provenance rather than recalculating from editable, planned, or client-provided quantities. Workflow version `1` receiving SHALL retain its established behavior for PKP-involved routes until Delivery 7.

#### Scenario: Receive mixed dispatched quantities in version 2
- **WHEN** an exact approved receipt references a dispatched line containing three non-tax and two taxed units
- **THEN** destination stock increases by those exact bucket quantities and the receipt transaction records the same provenance

#### Scenario: Receive a version 2 dispatched serial
- **WHEN** an exact serialized receipt is approved
- **THEN** every source-dispatched serial must own the expected active custody claim before its live location moves to destination and custody closes

#### Scenario: Client supplies different provenance
- **WHEN** a receipt request supplies tax, broken, allocation, stock, or claim values different from the source dispatch
- **THEN** the system ignores or rejects them and applies only locked immutable source provenance

#### Scenario: Receive through version 1
- **WHEN** a PKP-involved workflow version `1` transfer is received before Delivery 7
- **THEN** established legacy receiving behavior remains authoritative

### Requirement: Inventory movement transitions are atomic and concurrency safe
Dispatch and receiving MUST execute their status transition, stock updates, serial movement, inventory transactions, history, custody, claim, and applicable return effects within one database transaction using locked authoritative state.

#### Scenario: Failure after one line begins processing
- **WHEN** any later line, serial, stock update, transaction record, custody update, claim removal, or status update fails
- **THEN** all changes from that movement action roll back, including changes already made for earlier lines

#### Scenario: Concurrent dispatch requests
- **WHEN** two dispatch requests race for the same approved transfer
- **THEN** at most one dispatch changes inventory and the other observes a non-dispatchable locked status

#### Scenario: Concurrent receive requests
- **WHEN** two receipt approvals race for the same dispatched transfer or custody claim
- **THEN** at most one receipt increases destination inventory, moves serials, closes custody, removes claims, and completes the transfer

### Requirement: Movement actions enforce tenant and permission boundaries
Only an authorized user acting under the origin tenant SHALL prepare, submit, or approve forward dispatch, and only an authorized user acting under the destination tenant SHALL prepare, submit, review, reject, correct, or approve forward receipt; route models and domain executors MUST enforce the same transfer/movement/source aggregate.

#### Scenario: Destination user attempts initial dispatch
- **WHEN** a destination-tenant user directly invokes initial dispatch
- **THEN** the system rejects the action without inventory mutation

#### Scenario: Origin user attempts destination receipt
- **WHEN** an origin-tenant user directly invokes destination receipt preparation or approval
- **THEN** the system rejects the action without exposing the dispatch manifest or changing inventory

#### Scenario: Cross-transfer receipt movement is supplied
- **WHEN** a crafted request pairs a transfer with a movement or source dispatch from another aggregate
- **THEN** both the route boundary and locked domain executor reject it without effects

## ADDED Requirements

### Requirement: Version 2 forward-receipt approval is the atomic destination boundary
For eligible workflow version `2` routes, only approval of an exact pending `FORWARD_RECEIPT` SHALL add destination inventory, move exact serials to destination, close custody, remove active claims, create receipt transactions, approve/history-stamp the movement, and project the transfer header to `COMPLETED`.

#### Scenario: Submit receipt without approval
- **WHEN** a forward-receipt draft is submitted
- **THEN** it becomes pending without adding stock, moving serials, changing custody or claims, or changing transfer status

#### Scenario: Approve receipt once
- **WHEN** an exact and valid pending receipt is approved
- **THEN** its complete destination inventory, custody, serial, audit, movement, and header effects commit together exactly once

#### Scenario: Receipt approval fails
- **WHEN** comparison, provenance, stock, serial, custody, aggregate, or concurrency validation fails
- **THEN** no partial destination inventory, transaction, serial, custody, claim, history, movement, or header effect remains

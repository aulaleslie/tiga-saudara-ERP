## ADDED Requirements

### Requirement: Dispatch calculates authoritative non-tax-first allocation
At dispatch, the system MUST lock the transfer, relevant origin stock, and selected serial records, reload authoritative data, and calculate actual non-serialized allocation by consuming the applicable non-tax bucket before the corresponding taxed bucket.

#### Scenario: Dispatch uses only non-tax stock
- **WHEN** locked live non-tax stock fully covers an approved non-serialized requested quantity
- **THEN** dispatch deducts that quantity from non-tax stock and records zero dispatched taxed quantity

#### Scenario: Dispatch spills into taxed stock
- **WHEN** locked live non-tax stock covers only part of an approved non-serialized request and taxed stock covers the balance
- **THEN** dispatch deducts all available required non-tax quantity first, deducts the balance from taxed stock, and records both actual dispatched quantities

#### Scenario: Live total stock is insufficient
- **WHEN** locked eligible stock cannot cover the approved base quantity
- **THEN** dispatch fails atomically without moving serials, changing stock, creating inventory transactions, or changing transfer status

#### Scenario: Normal dispatch excludes broken stock
- **WHEN** normal eligible stock is insufficient but broken stock exists
- **THEN** dispatch fails rather than consuming broken stock

#### Scenario: Intentional broken dispatch allocates within broken buckets
- **WHEN** an approved line explicitly requests broken stock
- **THEN** dispatch consumes broken non-tax before broken taxed stock and does not consume normal stock

### Requirement: Tax-allocation drift requires informed acknowledgement
The system SHALL compare authoritative dispatch allocation with the approved preview and MUST obtain explicit acknowledgement when the actual taxed quantity or mandatory return obligation increases.

#### Scenario: Actual allocation matches approved preview
- **WHEN** live dispatch allocation does not increase the approved taxed quantity or mandatory return obligation
- **THEN** an authorized origin dispatcher can complete dispatch without an additional drift acknowledgement

#### Scenario: Actual taxed allocation increases
- **WHEN** non-tax stock changed after approval and dispatch would consume more taxed quantity than the approved preview
- **THEN** the system presents the recalculated line-level allocation and mandatory return impact and does not mutate inventory until the dispatcher acknowledges it

#### Scenario: Allocation changes after acknowledgement
- **WHEN** stock changes after a dispatcher acknowledges an allocation but before execution obtains its locks
- **THEN** the system refuses to apply the stale acknowledgement and requires review of the new allocation

### Requirement: Dispatch persists immutable actual provenance
Successful dispatch SHALL persist actual base quantities by tax and broken bucket, selected serial snapshots, dispatcher identity, timestamp, and inventory transaction references independently from the approved preview.

#### Scenario: Persist mixed actual allocation
- **WHEN** dispatch moves three non-tax and two taxed base units
- **THEN** the line records dispatched non-tax quantity three and dispatched taxed quantity two and inventory transactions reflect the same bucket changes

#### Scenario: Dispatch selected serials
- **WHEN** a serialized line is dispatched
- **THEN** the system validates each locked serial at the origin, derives its authoritative tax and broken provenance, moves it to the destination, and records the exact dispatched serial snapshot and history

### Requirement: Receiving mirrors actual dispatched provenance
Destination receiving SHALL add exactly the actual dispatched quantities and serials to the destination under their recorded tax and broken provenance rather than recalculating from editable or planned quantities.

#### Scenario: Receive mixed dispatched quantities
- **WHEN** the destination receives a dispatched line containing three non-tax and two taxed units
- **THEN** destination stock increases by those exact bucket quantities and the receiving transaction records the same provenance

#### Scenario: Receive a dispatched serial
- **WHEN** the destination receives a serialized transfer
- **THEN** every expected dispatched serial must be at the destination and remain consistent with the dispatched snapshot before receipt succeeds

### Requirement: Inventory movement transitions are atomic and concurrency safe
Dispatch and receiving MUST execute their status transition, stock updates, serial movement, inventory transactions, history, and return-obligation effects within one database transaction using locked authoritative state.

#### Scenario: Failure after one line begins processing
- **WHEN** any later line, serial, stock update, transaction record, or status update fails
- **THEN** all changes from that movement action roll back, including changes already made for earlier lines

#### Scenario: Concurrent dispatch requests
- **WHEN** two dispatch requests race for the same approved transfer
- **THEN** at most one dispatch changes inventory and the other observes a non-dispatchable locked status

#### Scenario: Concurrent receive requests
- **WHEN** two receive requests race for the same dispatched transfer
- **THEN** at most one receipt increases destination inventory

### Requirement: Movement actions enforce tenant and permission boundaries
Only an authorized user acting under the origin tenant SHALL dispatch an approved transfer, and only an authorized user acting under the destination tenant SHALL receive a dispatched transfer.

#### Scenario: Destination user attempts initial dispatch
- **WHEN** a destination-tenant user directly invokes initial dispatch
- **THEN** the system rejects the action without inventory mutation

#### Scenario: Origin user attempts destination receipt
- **WHEN** an origin-tenant user directly invokes destination receiving
- **THEN** the system rejects the action without inventory mutation


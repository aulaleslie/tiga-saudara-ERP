# stock-transfer-inventory-movement Specification

## Purpose
Authoritative tax-allocation drift review, immutable actual dispatch provenance, atomic inventory deduction, and version-aware forward-dispatch lifecycle transitions.

## Requirements

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

### Requirement: Tax-allocation drift uses version-aware dispatch review
For legacy workflow version `1`, the system SHALL preserve established tax-allocation drift acknowledgement. For workflow version `2`, the system SHALL derive and persist authoritative origin bucket allocation during forward-dispatch approval, SHALL treat bucket drift independently from product/quantity/serial request comparison, and SHALL expose exact allocation only to a user with stock visibility.

#### Scenario: Legacy taxed allocation increases
- **WHEN** a version `1` dispatch would increase taxed quantity or its legacy mandatory-return impact
- **THEN** the existing permission-aware drift acknowledgement behavior remains unchanged

#### Scenario: Version 2 physical count matches but allocation changes
- **WHEN** product, quantity, condition, and serial comparison passes but locked origin bucket allocation differs from the approved preview
- **THEN** bucket drift alone does not make the physical count a mismatch and approval may apply the authoritative allocation when total eligible stock is sufficient

#### Scenario: Blind version 2 approver encounters allocation drift
- **WHEN** authoritative allocation differs for an approver without stock visibility
- **THEN** no exact bucket, quantity, difference, hash, or return-impact information is sent to the browser

#### Scenario: Stock changes before approval locks
- **WHEN** total eligible stock becomes insufficient before version `2` approval obtains its locks
- **THEN** approval fails atomically without applying a stale allocation

### Requirement: Dispatch persists immutable actual provenance
Successful dispatch SHALL persist actual base quantities by tax and broken bucket, selected serial snapshots, dispatcher and approver identities, timestamps, and inventory transaction references independently from the approved preview. Version `1` SHALL retain its established serial-location behavior, while version `2` approved forward dispatch SHALL represent serialized goods through exclusive in-transit custody and leave live serial location at the last confirmed origin until receipt approval.

#### Scenario: Persist mixed actual allocation
- **WHEN** dispatch moves three non-tax and two taxed base units
- **THEN** the applicable immutable dispatch line records three non-tax and two taxed units and inventory transactions reflect the same origin bucket changes

#### Scenario: Dispatch selected serials through legacy workflow
- **WHEN** a workflow version `1` serialized line is dispatched
- **THEN** the established live-serial movement and exact dispatch snapshot behavior remains unchanged

#### Scenario: Dispatch selected serials through version 2
- **WHEN** a workflow version `2` serialized forward-dispatch movement is approved
- **THEN** the system validates each locked serial at the origin, derives authoritative tax and condition provenance, activates exclusive transit custody, retains origin as the live serial's last confirmed location, and records immutable serial and inventory history

### Requirement: Version 2 forward-dispatch approval is the atomic origin boundary
For workflow version `2`, only approval of an exact pending forward-dispatch movement SHALL deduct origin inventory, activate serial custody, create inventory transactions, approve the movement, record history, and project the transfer header to `DISPATCHED`, and all effects MUST occur in one locked idempotent transaction.

#### Scenario: Submit dispatch without approval
- **WHEN** a version `2` forward-dispatch draft is submitted
- **THEN** it becomes pending without deducting stock, creating inventory transactions, activating custody, or changing transfer status

#### Scenario: Approve dispatch once
- **WHEN** an exact and fulfillable pending forward dispatch is approved
- **THEN** its complete origin inventory, custody, audit, movement, and header effects commit together exactly once

#### Scenario: Approval fails or races
- **WHEN** validation fails, a later line fails, or concurrent approval loses the locked race
- **THEN** no partial inventory, custody, transaction, history, movement, or header effect remains

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

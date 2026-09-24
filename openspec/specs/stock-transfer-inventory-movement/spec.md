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
For workflow version `2`, approved forward receipt SHALL use the approved source dispatch as immutable quantity provenance, then apply those exact totals to destination buckets according to the approved route-policy snapshot: preserve source buckets for same-business routes, use only the applicable tax bucket for cross-business PKP destinations, and use only the applicable non-tax bucket for cross-business non-PKP destinations. Good/broken condition MUST remain unchanged.

#### Scenario: Same-business receipt
- **WHEN** an exact receipt approves under `PRESERVE` classification
- **THEN** destination stock receives the immutable source tax/non-tax allocation in the corresponding condition buckets

#### Scenario: Cross-business PKP destination
- **WHEN** an exact receipt approves for a PKP destination
- **THEN** the full received total is applied only to the destination tax bucket for the transfer condition

#### Scenario: Cross-business non-PKP destination
- **WHEN** an exact receipt approves for a non-PKP destination
- **THEN** the full received total is applied only to the destination non-tax bucket for the transfer condition

#### Scenario: Serialized reclassification
- **WHEN** a serialized receipt approves under tax or non-tax classification
- **THEN** each exact live serial receives the snapshotted destination tax ID or `null` respectively and immutable history preserves its prior and resulting tax identity

#### Scenario: Client supplies different provenance
- **WHEN** a receipt request supplies tax, allocation, stock, policy, or obligation values
- **THEN** the system ignores or rejects them and applies only locked authoritative source and route-policy records

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

### Requirement: Version 2 forward-receipt approval is the atomic destination boundary
For workflow version `2`, only approval of an exact pending `FORWARD_RECEIPT` SHALL apply destination-classified inventory, reclassify and move exact serials, close custody, remove active claims, create receipt transactions and snapshots, create any full-return obligations, approve/history-stamp the movement, and project the header to `COMPLETED` or `AWAITING_RETURN` according to the immutable policy.

#### Scenario: Submit receipt without approval
- **WHEN** a forward-receipt draft is submitted
- **THEN** it becomes pending without adding stock, reclassifying or moving serials, changing custody or claims, creating obligations, or changing transfer status

#### Scenario: Approve no-return receipt
- **WHEN** an exact receipt approves under a no-return policy
- **THEN** destination effects commit once, no obligation is created, and the transfer becomes `COMPLETED`

#### Scenario: Approve mandatory-return receipt
- **WHEN** an exact receipt approves under a mandatory-return policy
- **THEN** destination effects and full-product obligations commit once and the transfer becomes `AWAITING_RETURN`

#### Scenario: Receipt approval fails
- **WHEN** comparison, policy, tax resolution, provenance, stock, serial, custody, obligation, aggregate, or concurrency validation fails
- **THEN** no partial inventory, transaction, serial, custody, claim, obligation, history, movement, or header effect remains

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

### Requirement: Approved return receipt restores origin inventory atomically
Approval MUST lock authoritative source, receipt, product, origin stock, allocation, obligation, reservation, tax, serial, and custody state before adding the exact source-batch quantity to origin-classified inventory and recording immutable transaction and before/after provenance.

#### Scenario: Good stock returns to non-PKP origin
- **WHEN** an exact good-condition receipt approves for a non-PKP origin
- **THEN** its full quantity is added to good non-tax stock

#### Scenario: Broken stock returns to PKP origin
- **WHEN** an exact broken-condition receipt approves for a PKP origin
- **THEN** its full quantity is added to broken taxed stock under the processing-time tax snapshot

#### Scenario: Approval fails after inventory begins
- **WHEN** any later custody, reservation, obligation, history, or header invariant fails
- **THEN** all origin inventory and transaction effects roll back

### Requirement: Exact returned serials close custody at origin
Approved receipt SHALL require every serialized observation to identify the exact live serial and active claim from its source return dispatch, move it to the original location, apply origin tax classification, close movement custody, remove the active claim, and record immutable before/after history.

#### Scenario: Exact in-transit serial returns
- **WHEN** serial identity, product, condition, live location, source movement serial, and active claim all match
- **THEN** approval moves the serial to origin and makes it available under its new classification

#### Scenario: Claim is absent or mismatched
- **WHEN** the active claim does not identify the source return-dispatch movement and serial row
- **THEN** approval fails without moving any serial or inventory

#### Scenario: Serial text or product drifts
- **WHEN** locked live serial identity or product differs from the immutable source manifest
- **THEN** approval fails atomically

### Requirement: Return-receipt transaction provenance is exact
Each approved line SHALL store a canonical origin inventory transaction reference whose type, setting, location, product, quantity, classification, actor, and source-return lineage are validated and auditable.

#### Scenario: Approval replay references existing transaction
- **WHEN** approval is replayed with the completed action identity
- **THEN** no second transaction or inventory increment is created

### Requirement: Version 3 dispatch executes the full multi-route plan once
Version 3 confirmed approval SHALL lock the document and all affected inventory and serial state, validate aggregate source consumption, allocate non-serialized stock non-tax-first within the selected good or broken condition, and deduct each source allocation. It SHALL update product totals and preserve immutable applied buckets, serial identities, source/destination identities, transaction references, policy, actor, and timestamps. Serialized dispatch SHALL create exclusive claims while leaving live location at the last confirmed source.

#### Scenario: Mixed multi-source dispatch
- **WHEN** one document includes serialized and non-serialized products from several businesses
- **THEN** each allocation deducts its own exact source stock and all effects commit together once

#### Scenario: Later allocation fails
- **WHEN** any allocation cannot be fulfilled or any transaction, custody, snapshot, or event write fails
- **THEN** earlier allocation effects roll back with the entire approval

#### Scenario: Competing document claims a serial
- **WHEN** two approvals attempt to dispatch the same serial
- **THEN** exclusive claims and authoritative locking allow only one successful claim

### Requirement: Version 3 receipt applies approved allocations without a physical-count document
Whole-document receipt confirmation SHALL derive every destination quantity and serial from immutable dispatch evidence, apply each frozen destination classification, update location and global totals, close dispatch custody, and complete the transfer atomically. Client-supplied quantity, location, serial, or tax overrides MUST NOT change execution.

#### Scenario: Confirm receipt across destinations
- **WHEN** an authorized receiver confirms one document whose allocations target several locations
- **THEN** every approved destination receives its allocation in one transaction without user location selection

#### Scenario: Forged receipt allocation
- **WHEN** a client adds different quantities or destination IDs to the confirmation request
- **THEN** the system rejects the override or ignores it and never posts quantities outside the authoritative dispatch plan

### Requirement: Version 3 cancellation compensates exact dispatch deltas
Cancellation SHALL restore each immutable dispatch quantity to its original source and original good/broken tax/non-tax bucket, update product totals, release only matching active serial claims, close custody as cancelled, and append compensating inventory and serial histories. It MUST NOT overwrite current balances with old snapshots, reinterpret the source allocation using current tax settings, delete original transactions, or use return processing.

#### Scenario: Other stock transactions occur before cancellation
- **WHEN** unrelated stock movements alter a source balance after dispatch
- **THEN** cancellation adds only the dispatched deltas to that current balance and preserves unrelated movements

#### Scenario: Claim no longer matches
- **WHEN** a dispatched serial's live state or active claim no longer agrees with immutable dispatch evidence
- **THEN** cancellation fails atomically without restoring any partial stock

#### Scenario: Cancellation is retried
- **WHEN** a successful cancellation request is replayed
- **THEN** its result is returned without a second restoration, claim release, transaction, or event

### Requirement: Version 3 terminal actions share concurrency and idempotency boundaries
Dispatch, receipt, and cancellation SHALL use action-scoped idempotency and authoritative document locking. Receipt and cancellation MUST compete on the same dispatched state so only one can commit. Failure after processing any allocation SHALL roll back the entire operation.

#### Scenario: Receive races cancellation
- **WHEN** receipt confirmation and cancellation arrive concurrently
- **THEN** only one terminal operation changes inventory and the other reports the current state

#### Scenario: Receipt double click
- **WHEN** two confirmations arrive for the same dispatch
- **THEN** destinations, serials, histories, and completion are applied once

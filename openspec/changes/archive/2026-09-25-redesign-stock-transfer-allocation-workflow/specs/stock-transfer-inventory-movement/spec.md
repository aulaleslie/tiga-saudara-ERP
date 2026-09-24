# Spec Delta

## ADDED Requirements

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

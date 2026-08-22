## ADDED Requirements

### Requirement: Purchase and Sale references use authoritative sequence namespaces
The system SHALL allocate internal Purchase and Sale references from persisted sequence state keyed by document type, effective business setting, effective document prefix, reference year, and reference month. The persisted counter SHALL be authoritative after bootstrap and SHALL NOT infer the next number from document row ID ordering or the editable document date of the latest row.

#### Scenario: Out-of-order historical rows do not cause reuse
- **WHEN** existing references in a namespace have numeric suffixes `00179`, `00181`, and `00180` in non-sequential row-ID order
- **THEN** the next allocated suffix is `00182`

#### Scenario: Purchase and Sale namespaces are independent
- **WHEN** a Purchase and a Sale share the same setting, prefix text, year, and month
- **THEN** each document type advances only its own persisted sequence

#### Scenario: Prefix changes create a separate namespace
- **WHEN** a business changes its configured Purchase or Sale prefix
- **THEN** subsequent documents allocate from the namespace for the new prefix without rewriting or releasing historical references under the old prefix

### Requirement: Sequence allocation and document persistence are atomic
The system MUST lock the applicable sequence row, increment it, build the internal reference, and persist the Purchase or Sale within one database transaction. A failed transaction MUST leave neither a document nor an advanced counter, and the existing unique constraint on `(setting_id, reference)` MUST remain the final integrity guard.

#### Scenario: Document creation fails after allocation
- **WHEN** Purchase or Sale persistence fails after its next sequence number is selected
- **THEN** the document insert and counter increment are both rolled back

#### Scenario: Concurrent writers use the same namespace
- **WHEN** multiple independently committed workers create documents concurrently for the same namespace
- **THEN** every successful document receives a distinct monotonically increasing suffix and no worker receives a duplicate reference

#### Scenario: Unexpected database conflict occurs
- **WHEN** the unique constraint rejects an allocated reference because persisted sequence state is behind an existing document
- **THEN** the system reconciles the counter forward and performs no more than one bounded retry within a safe transaction boundary

### Requirement: All production Purchase creation paths use the shared allocator
Every production path that creates or reallocates an internal Purchase reference SHALL use the shared allocator, including standard creation, imports, duplication flows, model fallback behavior, and authorized cross-business draft movement. External supplier numbers SHALL remain separate from the internal Purchase reference.

#### Scenario: Imported Purchase is created
- **WHEN** a Purchase import creates a document for a target business and period
- **THEN** its internal reference comes from the same namespace used by an ordinary Purchase created for that business and period

#### Scenario: Purchase draft moves business
- **WHEN** an authorized user moves a drafted Purchase to another business
- **THEN** the system atomically assigns a new reference from the target business namespace and does not release the historical source reference

#### Scenario: Production code attempts an unallocated Purchase insert
- **WHEN** a production Purchase writer does not supply a reference allocated through the shared mechanism
- **THEN** the model boundary delegates to the shared allocator safely or rejects the unsupported write instead of running an independent numbering algorithm

### Requirement: All production Sale creation paths use the shared allocator
Every production path that creates or reallocates an internal Sale reference SHALL use the shared allocator, including standard creation, imports, model fallback behavior, authorized cross-business draft movement, and Sales produced by POS checkout. External customer invoice numbers SHALL remain separate from the internal Sale reference.

#### Scenario: Imported Sale is created
- **WHEN** a Sale import creates a document for a target business and period
- **THEN** its internal reference comes from the same namespace used by an ordinary Sale created for that business and period

#### Scenario: Sale draft moves business
- **WHEN** an authorized user moves a drafted Sale to another business
- **THEN** the system atomically assigns a new reference from the target business namespace and does not release the historical source reference

#### Scenario: Production code attempts an unallocated Sale insert
- **WHEN** a production Sale writer does not supply a reference allocated through the shared mechanism
- **THEN** the model boundary delegates to the shared allocator safely or rejects the unsupported write instead of running an independent numbering algorithm

### Requirement: POS-generated Sales allocate against their actual owner businesses
Every Sale generated by POS checkout SHALL receive its internal reference from the Sale sequence namespace belonging to the persisted Sale's effective owner setting. This SHALL cover inline checkout, non-stock owner resolution, and every owner-specific Sale generated by split posting.

#### Scenario: Split checkout spans two businesses
- **WHEN** a POS checkout produces one Sale owned by business A and one Sale owned by business B
- **THEN** each Sale uses the prefix and counter belonging to its own `setting_id`

#### Scenario: Multiple split groups share one owner
- **WHEN** multiple POS split groups produce separate Sales under the same owner namespace
- **THEN** those Sales receive distinct consecutive references from that owner namespace

#### Scenario: Concurrent POS and standard Sale creation
- **WHEN** a standard Sale and one or more POS-generated Sales concurrently use the same owner namespace
- **THEN** all successful Sales receive unique references from the single shared counter

### Requirement: Multi-owner POS allocation uses deterministic lock ordering
Before a POS split checkout creates owner-specific Sales, the system MUST resolve every required Sale sequence namespace and acquire their locks in a deterministic canonical order. The full checkout, all sequence changes, and all generated Sales SHALL remain within the checkout's atomic transaction.

#### Scenario: Concurrent checkouts encounter reverse cart owner order
- **WHEN** two concurrent POS checkouts require business A and business B sequences but encounter those owners in opposite cart order
- **THEN** both checkouts request sequence locks in the same canonical order and avoid an application-induced circular wait

#### Scenario: Later split group fails
- **WHEN** a POS checkout allocates references for multiple owner groups and a later group fails to post
- **THEN** every generated Sale and every sequence increment from that checkout is rolled back

### Requirement: Reference lifecycle rules preserve audit identity
Editing a Purchase or Sale date SHALL NOT regenerate its existing internal reference. Only an authorized business move of a drafted document SHALL reallocate its reference; non-draft documents SHALL retain their original setting and reference under existing immutability rules. Archived, cancelled, rejected, or otherwise historical references SHALL remain reserved.

#### Scenario: Document date changes after creation
- **WHEN** an authorized edit changes the date of an existing document without changing its business
- **THEN** its internal reference remains unchanged

#### Scenario: Historical document is archived
- **WHEN** a Purchase or Sale is archived
- **THEN** its internal reference remains unavailable for future allocation

#### Scenario: Non-draft business move is attempted
- **WHEN** a user attempts to move a non-draft Purchase or Sale to another business
- **THEN** the existing move restriction remains enforced and no sequence is allocated

### Requirement: Existing sequence state is reconciled before cutover
The system SHALL provide a repeatable dry-run and bootstrap operation that examines active and archived Purchase and Sale references, derives the highest valid suffix per namespace, and reports malformed references, unexpected prefixes, embedded reference-period differences from document dates, and any persisted counter below existing data. Bootstrap SHALL only advance counters and SHALL NOT rewrite historical references.

#### Scenario: Dry-run finds reference and date period drift
- **WHEN** an existing reference embeds August 2026 but its document date is outside August 2026
- **THEN** the dry-run reports the drift while assigning the reference to its embedded namespace for counter reconciliation

#### Scenario: Existing counter is lower than history
- **WHEN** bootstrap finds a persisted counter below the maximum valid historical suffix
- **THEN** it advances the counter to the historical maximum and does not modify any document

#### Scenario: Malformed historical reference exists
- **WHEN** a reference cannot be parsed unambiguously into its expected namespace and numeric suffix
- **THEN** the operation reports it for review and does not silently interpret or rewrite it

### Requirement: Failed submissions remain safely retryable
Purchase and Sale submission idempotency claims SHALL distinguish successful completion from a rolled-back failure. A failed attempt MUST become retryable without waiting for the normal claim TTL, while a completed attempt MUST continue to prevent duplicate document creation.

#### Scenario: Purchase insert fails on first attempt
- **WHEN** a Purchase submission claims its idempotency token and its database transaction rolls back
- **THEN** the claim is released or marked failed and the same user action can be retried safely

#### Scenario: Sale submission succeeds
- **WHEN** a Sale submission commits successfully
- **THEN** its completed idempotency state prevents the same token from creating another Sale

#### Scenario: POS checkout retry follows rollback
- **WHEN** a split POS checkout fails and rolls back after sequence allocation
- **THEN** its existing checkout idempotency behavior permits the intended safe retry without leaving Sales or advanced counters

### Requirement: Allocation and reconciliation events are observable
The system SHALL emit structured diagnostics for allocation, bootstrap, reconciliation, conflict retry, rollback, and terminal failure without logging sensitive business payloads. Diagnostics SHALL identify document type, setting, namespace, allocated suffix or counter state, and resulting reference when one exists.

#### Scenario: Counter conflict is reconciled
- **WHEN** the allocator advances a stale counter after a unique conflict
- **THEN** a structured warning records the affected namespace, previous state, reconciled state, and retry outcome

### Requirement: Scoped MySQL verification matches production behavior
The change MUST provide focused verification against a disposable Dockerized MySQL Community Server 8.0.44 instance configured with InnoDB, `REPEATABLE-READ`, the production SQL mode, `utf8mb4`, `utf8mb4_0900_ai_ci`, and UTC+08:00 timezone behavior. Existing SQLite tests MAY remain for fast compatibility checks, but SQLite results SHALL NOT substitute for row-locking and multi-process concurrency verification.

#### Scenario: MySQL test environment starts
- **WHEN** the scoped database test workflow starts its container
- **THEN** it verifies the server version and required database settings before migrating or running tests

#### Scenario: Real concurrency test executes
- **WHEN** the allocator concurrency test runs
- **THEN** independently committed PHP worker processes contend against the same MySQL test schema using a deterministic start barrier and bounded timeout

#### Scenario: Scoped verification is requested
- **WHEN** this change is validated locally or in CI
- **THEN** only the dedicated allocator, Purchase, Sale, POS, bootstrap, idempotency, and concurrency tests are required rather than the full repository suite


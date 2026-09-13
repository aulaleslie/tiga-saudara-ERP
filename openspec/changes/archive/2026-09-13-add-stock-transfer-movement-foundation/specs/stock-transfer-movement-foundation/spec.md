## ADDED Requirements

### Requirement: Transfers support a dormant versioned movement foundation
The system SHALL store a workflow version on every stock transfer, SHALL preserve all existing transfers under legacy workflow version `1`, and MUST NOT route production stock-transfer actions through movement documents in this change.

#### Scenario: Existing transfer receives the compatibility version
- **WHEN** the movement-foundation migration is applied to an existing transfer in any lifecycle state
- **THEN** the transfer remains workflow version `1` with its status and current lifecycle behavior unchanged

#### Scenario: Newly created transfer remains legacy during the dormant delivery
- **WHEN** a transfer is created after this foundation is deployed but before a later workflow cutover
- **THEN** it remains workflow version `1` and follows the existing header lifecycle

#### Scenario: Dormant movement approval has no operational effects
- **WHEN** a movement attempt is approved through the foundation domain service in focused verification
- **THEN** no product stock, inventory transaction, return obligation, serial location, serial availability, or transfer-header status is changed

### Requirement: Movement attempts identify type, revision, and operational context
The system SHALL represent each movement submission attempt as a record bound to one transfer, one movement type, one revision, the exact approved transfer revision, explicit operational source and destination locations, and one stock condition.

#### Scenario: Create the first forward-dispatch attempt
- **WHEN** an authorized foundation service creates a forward-dispatch draft for an eligible transfer revision
- **THEN** revision `1` is stored with type `FORWARD_DISPATCH`, its transfer revision, operational locations, and the transfer's explicit stock condition

#### Scenario: Attempt uses inconsistent operational context
- **WHEN** a movement draft specifies locations, condition, or transfer revision inconsistent with its transfer
- **THEN** creation is rejected without persisting the attempt

#### Scenario: Recount references its source manifest
- **WHEN** a receipt attempt is created against an approved dispatch attempt
- **THEN** it stores the exact source movement reference and rejects a source belonging to another transfer, an incompatible type, or a non-approved state

### Requirement: Movement submissions use immutable superseding revisions
The system SHALL permit draft editing, SHALL freeze an attempt and its lines and serials when submitted, and SHALL create correction drafts as new monotonically increasing revisions that reference the rejected attempt they supersede.

#### Scenario: Edit an open draft
- **WHEN** an authorized actor updates a `DRAFT` attempt using its current concurrency revision
- **THEN** the draft contents and last editor are updated without creating a submitted revision

#### Scenario: Submitted attempt cannot be edited
- **WHEN** an actor attempts to change the header, lines, or serials of a `PENDING`, `APPROVED`, `REJECTED`, or `CANCELLED` attempt
- **THEN** the mutation is rejected and the stored submission remains unchanged

#### Scenario: Correct a rejected attempt
- **WHEN** an authorized actor starts a correction for revision `1` in `REJECTED`
- **THEN** the system creates revision `2` in `DRAFT` with a supersedes reference to revision `1` and preserves revision `1` unchanged

#### Scenario: Stale draft update
- **WHEN** two actors edit the same draft and the later request carries a stale concurrency revision
- **THEN** the stale update is rejected without overwriting the newer draft

### Requirement: Movement lifecycle transitions are explicit and audited
The system SHALL allow only `DRAFT` to `PENDING`, `PENDING` to `APPROVED` or `REJECTED`, and `DRAFT` to `CANCELLED`, and SHALL record each transition with its actor, timestamp, movement revision, reason where applicable, and scoped idempotency identity.

#### Scenario: Submit a movement draft
- **WHEN** an authorized actor submits a complete `DRAFT` attempt
- **THEN** it becomes `PENDING`, its submitted content becomes immutable, and a submission history entry is recorded

#### Scenario: Reject a pending attempt
- **WHEN** an authorized approver rejects a `PENDING` attempt with a nonempty reason
- **THEN** it becomes `REJECTED` and the reason and approver are retained in immutable history

#### Scenario: Cancel a draft
- **WHEN** an authorized actor cancels a `DRAFT` attempt with a reason
- **THEN** it becomes `CANCELLED`, remains retained, and cannot be submitted or edited

#### Scenario: Invalid transition
- **WHEN** an actor requests a state transition outside the defined lifecycle
- **THEN** the transition is rejected without changing movement or history records

### Requirement: Movement attempts prevent competing operational documents
The system MUST serialize movement-attempt creation per transfer and type, MUST permit at most one open `DRAFT` or `PENDING` attempt for that pair, MUST permit at most one approved attempt, and MUST assign unique increasing revisions.

#### Scenario: Competing open attempt
- **WHEN** a draft or pending attempt already exists for a transfer and movement type and another actor attempts to create one
- **THEN** creation is rejected and only the original open attempt remains

#### Scenario: Duplicate approved attempt
- **WHEN** an approved attempt already exists for a transfer and movement type
- **THEN** another attempt of that type cannot be approved

#### Scenario: Concurrent revision creation
- **WHEN** concurrent requests try to create the next revision for the same transfer and type
- **THEN** parent locking and unique revision identity allow at most one request to persist that revision

### Requirement: Movement lines use canonical base-unit quantities
The system SHALL persist at most one line per product in a movement attempt using non-negative inventory-compatible decimal base-unit quantity, independent of scan order or unit-conversion input context.

#### Scenario: Repeated entries for one product
- **WHEN** input contains repeated scans or rows for the same product
- **THEN** the foundation canonicalizes them to one movement line with their valid normalized base-unit total

#### Scenario: Invalid movement quantity
- **WHEN** a line contains a negative value, unsupported precision, or a conversion that cannot be normalized authoritatively
- **THEN** the movement cannot be submitted

#### Scenario: Movement condition differs from product entry
- **WHEN** a line or serial entry conflicts with the movement's `GOOD` or `BREAKAGE` condition
- **THEN** the movement cannot be submitted

### Requirement: Serialized movement selections are normalized and auditable
The system SHALL store each selected serial as a normalized row linked to its movement line, SHALL prevent duplicate serial selection within an attempt, and SHALL snapshot product, serial identity, stock condition, and tax provenance at submission.

#### Scenario: Submit a serialized line
- **WHEN** a serialized movement line is submitted with distinct eligible serial selections
- **THEN** its base-unit quantity equals its unique serial-row count and the serial snapshots become immutable

#### Scenario: Duplicate serial selection
- **WHEN** the same serial is supplied more than once within an attempt
- **THEN** submission is rejected without creating duplicate movement-serial rows

#### Scenario: Serialized quantity mismatch
- **WHEN** a serialized line's entered quantity differs from its unique serial count
- **THEN** submission is rejected without changing movement state

### Requirement: Transit custody storage remains inactive
The system SHALL provide movement-serial fields and relationships capable of recording later in-transit custody, but this change MUST NOT activate custody, alter serialized-product location, or change serial availability.

#### Scenario: Approve a dormant movement containing serials
- **WHEN** a movement containing serialized rows is approved under this foundation
- **THEN** its serial snapshots remain stored while the live serial location, status, reservation flags, and availability remain unchanged

#### Scenario: Existing serial query runs after deployment
- **WHEN** an existing sale, dispatch, return, or transfer query resolves serial availability
- **THEN** dormant movement rows do not change the query result

### Requirement: Movement actions are idempotent within their exact scope
The system SHALL scope idempotency to movement, revision, and action and MUST NOT duplicate transitions, history, or related records when the same completed action is repeated with the same key.

#### Scenario: Repeat movement submission
- **WHEN** the same draft submission action is repeated with the same idempotency key
- **THEN** the existing pending result is returned and only one submission history entry exists

#### Scenario: Reuse a key for another action
- **WHEN** an idempotency key used for submission is later supplied for an approval action
- **THEN** the approval is evaluated in its distinct action scope rather than mistaken for the submission result

### Requirement: Movement preparation and approval permissions are separate
The system SHALL register separate dispatch-create, dispatch-approval, receive-create, and receive-approval permissions, SHALL map legacy workflow permissions to compatible new permissions, and SHALL NOT infer stock visibility from any movement permission.

#### Scenario: Legacy dispatch role is migrated
- **WHEN** a role holds `stockTransfers.dispatch` during compatibility synchronization
- **THEN** it receives `stockTransfers.dispatch.create` and `stockTransfers.dispatch.approval`

#### Scenario: Legacy receive role is migrated
- **WHEN** a role holds `stockTransfers.receive` during compatibility synchronization
- **THEN** it receives `stockTransfers.receive.create` and `stockTransfers.receive.approval`

#### Scenario: Compatibility does not disclose stock
- **WHEN** a role receives new movement permissions through legacy compatibility but lacks `stockTransfers.view-system-stock`
- **THEN** it does not receive the stock-visibility permission or any sensitive movement projection

#### Scenario: New permissions do not replace legacy route checks
- **WHEN** an existing production dispatch or receipt route is invoked after this foundation is deployed
- **THEN** its existing legacy permission and lifecycle behavior remain in effect

### Requirement: Movement foundation has no production-facing surface
The system MUST NOT expose movement creation, mutation, approval, quantities, manifests, serial snapshots, comparisons, or custody through production routes, Livewire components, browser views, APIs, prints, or exports in this change.

#### Scenario: User holds every new movement permission
- **WHEN** a user with all four new permissions navigates the existing stock-transfer interface
- **THEN** no new movement action or sensitive movement data is exposed and the existing workflow remains unchanged

#### Scenario: Existing transfer response is inspected
- **WHEN** an existing transfer page or Livewire payload is rendered after deployment
- **THEN** dormant movement data is absent from the response regardless of the user's movement permissions

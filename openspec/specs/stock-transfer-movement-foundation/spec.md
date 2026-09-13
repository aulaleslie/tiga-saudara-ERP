# stock-transfer-movement-foundation Specification

## Purpose
Staged versioned movement foundation, monotonically increasing superseding revisions, exclusive active transit custody, explicit confirmed-zero observation semantics, and permission isolation.

## Requirements

### Requirement: Transfers support a staged versioned movement foundation
The system SHALL store a workflow version on every stock transfer, SHALL preserve existing and newly approved production transfers under legacy workflow version `1` while version `2` activation is disabled, and SHALL permit only the gated forward-dispatch capability to exercise version `2` movement effects in focused non-production verification until forward receipt is available.

#### Scenario: Existing transfer remains compatible
- **WHEN** the approved-forward-dispatch change is deployed for an existing transfer in any lifecycle state
- **THEN** its workflow version, status, and current lifecycle behavior remain unchanged

#### Scenario: Newly created production transfer remains legacy before coordinated cutover
- **WHEN** a production transfer is created or approved while version `2` activation is disabled
- **THEN** it remains workflow version `1` and follows the existing header lifecycle

#### Scenario: Gated version 2 forward-dispatch approval
- **WHEN** the version `2` forward-dispatch path is exercised through an authorized focused-test or non-production activation boundary
- **THEN** only the defined forward-dispatch approval effects occur atomically and no destination receipt, tax reclassification, or return movement effect occurs

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

### Requirement: Transit custody activates only for approved version 2 forward dispatch
The system SHALL activate serialized transit custody only as part of successful workflow version `2` forward-dispatch approval, SHALL leave live serial location at its last confirmed origin, and MUST NOT change custody for draft, pending, rejected, cancelled, failed, or legacy movements.

#### Scenario: Approve version 2 serialized forward dispatch
- **WHEN** an eligible serialized forward dispatch completes approval atomically
- **THEN** its movement serials become exclusively in transit while live serial locations remain unchanged

#### Scenario: Movement does not approve
- **WHEN** preparation, submission, comparison, stock validation, serial validation, or approval fails
- **THEN** transit custody remains inactive and existing serial availability is unchanged

#### Scenario: Legacy serial dispatch
- **WHEN** a workflow version `1` transfer dispatches through the existing path
- **THEN** its established serial behavior remains unchanged by the movement-custody capability

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

### Requirement: Movement foundation exposes only gated forward dispatch
The system SHALL expose movement creation, mutation, submission, review, rejection, correction, and approval only for forward dispatch through version-aware production routes guarded by an activation boundary, action permissions, operational ownership, and stock-visibility projections; other movement types MUST remain without production-facing surfaces in this change.

#### Scenario: Authorized version 2 dispatch preparation
- **WHEN** activation is enabled in a permitted environment and an eligible origin user invokes forward-dispatch preparation
- **THEN** the gated movement surface is available using the user's blind or privileged projection

#### Scenario: Receipt or return movement route attempted
- **WHEN** any user attempts to access a forward-receipt, return-dispatch, or return-receipt production movement surface in this change
- **THEN** no such surface is available and no movement or inventory effect occurs

#### Scenario: Activation remains disabled
- **WHEN** a user holds every movement permission but production version `2` activation is disabled
- **THEN** no new operational movement action is available and version `1` behavior remains unchanged

### Requirement: Confirmed zero is a complete movement observation
Movement lines SHALL store count confirmation independently from quantity, and a submitted forward-dispatch attempt SHALL treat a confirmed zero expected-product line as complete while treating an unconfirmed line as incomplete.

#### Scenario: Persist confirmed zero
- **WHEN** an operator explicitly confirms zero for an approved product
- **THEN** its movement line retains zero quantity with confirmed state for immutable submission and comparison

#### Scenario: Unconfirmed zero remains incomplete
- **WHEN** an approved product has zero quantity without explicit confirmation
- **THEN** the forward-dispatch attempt cannot be submitted

# stock-transfer-movement-foundation Specification

## Purpose
Staged versioned movement foundation, monotonically increasing superseding revisions, exclusive active transit custody, explicit confirmed-zero observation semantics, and permission isolation.

## Requirements

### Requirement: Transfers support a staged versioned movement foundation
The system SHALL store a workflow version on every stock transfer and SHALL assign workflow version `2` prospectively to new same-business, cross-business non-PKP-to-non-PKP, and PKP-involved transfers once the applicable route-policy snapshot can be committed at approval. Existing transfers SHALL retain their stored workflow version and behavior without backfill or reinterpretation.

#### Scenario: New no-return transfer uses version 2
- **WHEN** a new transfer is same-business or both participating businesses are non-PKP
- **THEN** approval snapshots the route and enables workflow version `2` forward dispatch and receipt

#### Scenario: New PKP-involved transfer uses version 2
- **WHEN** a new cross-business transfer involving PKP is approved after Delivery 7 activation
- **THEN** approval snapshots destination classification and mandatory-return policy before enabling workflow version `2`

#### Scenario: Policy snapshot cannot be created
- **WHEN** route or tax policy cannot be resolved authoritatively during approval
- **THEN** approval fails without changing workflow version or transfer state

#### Scenario: No historical transfer migration
- **WHEN** Delivery 7 is deployed
- **THEN** existing transfer records receive no legacy transaction backfill, policy snapshot, obligation, reopening, or reinterpretation

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
The system MUST serialize revision creation within each movement lineage, MUST permit at most one open `DRAFT` or `PENDING` attempt per lineage, and MUST permit at most one approved attempt in a forward movement or receipt lineage. It SHALL permit multiple independently approved `RETURN_DISPATCH` lineages for one transfer so concurrent partial batches can exist, while assigning unique stable identities and increasing correction revisions within each lineage.

#### Scenario: Competing open attempt in one lineage
- **WHEN** a draft or pending correction already exists for a movement lineage and another actor attempts to create a competing revision
- **THEN** creation is rejected and only the original open attempt remains

#### Scenario: Duplicate approved attempt in one lineage
- **WHEN** an approved attempt already exists in a lineage
- **THEN** another revision in that lineage cannot be approved

#### Scenario: Separate partial return batches
- **WHEN** one return-dispatch batch is already approved and capacity remains outstanding
- **THEN** a new independent return-dispatch lineage may be created and approved

#### Scenario: Concurrent revision creation
- **WHEN** concurrent requests try to create the next correction revision in the same lineage
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

### Requirement: Transit custody closes only for approved exact forward receipt
The system SHALL activate serialized transit custody only on approved workflow version `2` forward dispatch and SHALL close that custody, move live serial location to destination, and remove the active claim only within successful approval of the exact linked forward receipt.

#### Scenario: Approve serialized forward dispatch
- **WHEN** an eligible serialized forward dispatch completes approval atomically
- **THEN** its movement serials become exclusively in transit while live serial locations remain at their last confirmed origin

#### Scenario: Approve exact linked receipt
- **WHEN** the linked receipt exactly matches and completes approval atomically
- **THEN** live serial locations move to destination, movement custody closes, and active transfer claims are removed

#### Scenario: Receipt does not approve
- **WHEN** receipt preparation, submission, comparison, rejection, correction, cancellation, or approval fails
- **THEN** dispatched serials remain in transit and unavailable for competing operations

#### Scenario: Legacy serial lifecycle
- **WHEN** a workflow version `1` transfer dispatches or receives
- **THEN** its established serial behavior remains unchanged by movement custody

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

### Requirement: Movement foundation exposes gated forward dispatch and receipt
The system SHALL expose movement creation, mutation, submission, review, rejection, correction, and approval for forward dispatch, forward receipt, and eligible return dispatch through version-aware routes guarded by route eligibility, action permissions, operational ownership, scoped aggregate identity, route policy, source lineage, and stock-visibility projections; return receipt MUST remain without a production-facing surface in this change.

#### Scenario: Authorized origin forward-dispatch action
- **WHEN** an eligible origin user invokes a workflow version `2` forward-dispatch action
- **THEN** the dispatch movement surface is available using the user's permitted projection

#### Scenario: Authorized destination forward-receipt action
- **WHEN** an eligible destination user invokes a workflow version `2` forward-receipt action
- **THEN** preparation uses the universally blind projection and approval uses the approver's visibility-aware projection

#### Scenario: Authorized destination return-dispatch action
- **WHEN** a destination-side user with the applicable dispatch action permission accesses an eligible mandatory-return workflow version `2` transfer
- **THEN** the return-dispatch surface is available using the user's visibility-aware projection

#### Scenario: Return-receipt route attempted
- **WHEN** any user attempts to access a return-receipt movement surface in this change
- **THEN** no such surface is available and no movement, inventory, obligation, or custody effect occurs

#### Scenario: Legacy workflow invokes movement route
- **WHEN** a workflow version `1` transfer invokes a version `2` movement action
- **THEN** the action is rejected and established legacy behavior remains authoritative

### Requirement: Return-dispatch movements retain exact source and batch lineage
Every return-dispatch lineage SHALL reference the exact approved forward receipt that created its obligations, the committed transfer policy/revision, and a stable batch identity shared by its rejected and correction revisions.

#### Scenario: Create a new partial batch
- **WHEN** an eligible transfer has unreserved outstanding obligation capacity
- **THEN** the system creates a new batch lineage whose first return-dispatch revision references the approved forward receipt

#### Scenario: Source receipt belongs to another transfer
- **WHEN** a crafted request supplies an approved receipt from another transfer or revision
- **THEN** creation or mutation is rejected without persisting a movement

#### Scenario: Correct a rejected batch
- **WHEN** a rejected return-dispatch attempt is corrected
- **THEN** the new revision retains the same batch and source lineage while superseding the rejected attempt

### Requirement: Confirmed zero is a complete movement observation
Movement lines SHALL store count confirmation independently from quantity, and a submitted forward-dispatch attempt SHALL treat a confirmed zero expected-product line as complete while treating an unconfirmed line as incomplete.

#### Scenario: Persist confirmed zero
- **WHEN** an operator explicitly confirms zero for an approved product
- **THEN** its movement line retains zero quantity with confirmed state for immutable submission and comparison

#### Scenario: Unconfirmed zero remains incomplete
- **WHEN** an approved product has zero quantity without explicit confirmation
- **THEN** the forward-dispatch attempt cannot be submitted

### Requirement: Empty receipt confirmation is explicit and auditable
A forward-receipt movement SHALL store document-level physical-count confirmation independently from movement lines, including confirmer and timestamp, so an intentional empty observation can be submitted without exposing or synthesizing expected lines.

#### Scenario: Explicitly confirm empty receipt
- **WHEN** an authorized destination preparer confirms that no goods were physically received
- **THEN** the draft records the confirmation actor and timestamp and may be submitted empty

#### Scenario: Empty draft is not confirmed
- **WHEN** a draft contains no positive observations and lacks current document-level confirmation
- **THEN** it is incomplete and cannot be submitted

#### Scenario: Observations change after confirmation
- **WHEN** receipt observations are added, removed, or changed after empty confirmation
- **THEN** the obsolete document-level confirmation is cleared

### Requirement: Receipt corrections restart blind
A correction created for a rejected forward receipt SHALL reference and preserve the rejected revision but SHALL start with no copied lines, serials, quantities, or hidden expectations.

#### Scenario: Correct rejected receipt
- **WHEN** an authorized destination preparer starts a correction for a rejected receipt
- **THEN** the next revision is an empty blind draft linked through `supersedes_movement_id`

#### Scenario: Inspect correction payload
- **WHEN** the correction is rendered for preparation
- **THEN** neither rejected observations nor source dispatch expectations appear unless newly entered by the operator

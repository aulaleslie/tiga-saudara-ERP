# stock-transfer-approval-lifecycle Specification

## Purpose
Stock-transfer lifecycle states, approval authority, rejection and revision, concurrency-safe transitions, and append-only history. This covers legacy workflows and the prospective version 3 flow, in which approval dispatches immediately and a dispatched document ends in either confirmed receipt or reasoned cancellation.

## Requirements

### Requirement: Approval authority uses the dedicated permission
The system SHALL require `stockTransfers.approval` for approving or rejecting a pending stock transfer and SHALL NOT treat `stockTransfers.edit` as approval authority.

#### Scenario: Creator self-approves with approval permission
- **WHEN** the transfer creator also has `stockTransfers.approval`, acts under the origin tenant, and approves a valid `PENDING` transfer
- **THEN** the system records the creator as approver and transitions the transfer to `APPROVED`

#### Scenario: Editor without approval permission cannot decide
- **WHEN** a user has `stockTransfers.edit` but not `stockTransfers.approval`
- **THEN** the system hides approval actions and rejects direct approve or reject requests

#### Scenario: Non-origin tenant cannot approve
- **WHEN** an otherwise permitted user attempts approval while the active tenant does not own the origin location
- **THEN** the system rejects the action without changing the transfer

### Requirement: Approval authorizes dispatch without reserving stock
Approval SHALL authorize the requested products, base quantities, transfer modes, and serial intent for later dispatch, but SHALL NOT reserve, deduct, or guarantee inventory availability.

#### Scenario: Approve a structurally valid request
- **WHEN** an authorized approver approves a valid pending transfer
- **THEN** the system records the approved revision and allocation preview without changing any product stock or serial location

#### Scenario: Stock changes after approval
- **WHEN** origin stock changes after approval but before dispatch
- **THEN** the transfer remains approved and dispatch performs a new authoritative availability and allocation check

### Requirement: Approved transfers are immutable and archivable before dispatch
An approved transfer SHALL reject ordinary edits, resubmission, and rejection, and SHALL permit an authorized origin user to archive it with a non-empty reason only before dispatch.

#### Scenario: Archive an approved undispatched transfer
- **WHEN** an authorized origin user archives an `APPROVED` transfer with a reason before inventory movement
- **THEN** the system atomically records the actor, time, reason, and transition to `ARCHIVED` without changing inventory

#### Scenario: Reject archive after dispatch
- **WHEN** a user attempts to archive a dispatched or later-stage transfer
- **THEN** the system rejects the action and preserves its lifecycle and inventory records

#### Scenario: Attempt to edit an approved transfer
- **WHEN** any user submits changed lines or locations for an approved transfer
- **THEN** the system rejects the request and preserves the approved revision unchanged

### Requirement: Rejection requires acknowledgement before revision
The system SHALL require a non-empty rejection reason, preserve the rejected revision, and allow an authorized origin user to acknowledge the rejection before returning the transfer to editable `DRAFT` status.

#### Scenario: Reject a pending transfer with reason
- **WHEN** an authorized approver rejects a `PENDING` transfer with a non-empty reason
- **THEN** the system records the reason and actor and transitions the transfer to `REJECTED`

#### Scenario: Reject without reason
- **WHEN** an approver submits a rejection without a meaningful reason
- **THEN** the system rejects the action and leaves the transfer `PENDING`

#### Scenario: Acknowledge rejection
- **WHEN** an authorized origin user acknowledges the current rejection
- **THEN** the system records the acknowledgement and transitions the transfer to editable `DRAFT` without erasing the rejected revision or reason

#### Scenario: Resubmit an acknowledged draft
- **WHEN** an authorized origin user updates and resubmits an acknowledged draft
- **THEN** the system increments the review revision and transitions the transfer to `PENDING` for a new approval decision

### Requirement: Lifecycle history is append-only and auditable
The system SHALL record every meaningful stock-transfer lifecycle action with transfer revision, previous state, next state, actor, timestamp, reason when applicable, and relevant metadata without overwriting earlier decisions.

#### Scenario: Multiple reject and resubmit cycles
- **WHEN** a transfer is rejected, acknowledged, revised, resubmitted, and rejected again
- **THEN** the history displays both rejection decisions and their corresponding revisions in chronological order

#### Scenario: Same user creates and approves
- **WHEN** self-approval occurs
- **THEN** history records distinct creation/submission and approval actions even though their actor IDs match

### Requirement: Lifecycle transitions are concurrency safe and idempotent
The system MUST lock the authoritative transfer state during each lifecycle mutation and SHALL allow only the valid transition from the locked current state.

#### Scenario: Concurrent approve and reject
- **WHEN** approve and reject requests race for the same pending revision
- **THEN** exactly one valid transition succeeds and the other reports that the state has changed

#### Scenario: Repeated identical lifecycle request
- **WHEN** the same idempotent lifecycle request is submitted more than once
- **THEN** the system produces at most one state change and one corresponding history action

### Requirement: Undispatched transfer lines initialize dispatch counters to zero
Every newly persisted `transfer_products` row SHALL initialize `dispatched_quantity`, `dispatched_quantity_tax`, `dispatched_quantity_non_tax`, `dispatched_quantity_broken_tax`, and `dispatched_quantity_broken_non_tax` to zero when dispatch values are omitted. The database schema MUST enforce these non-null zero defaults consistently on MySQL-compatible production databases.

#### Scenario: Save destination-optional draft without dispatch fields
- **WHEN** an authorized user saves a valid transfer draft with an origin and product lines but no destination or dispatch data
- **THEN** the draft and lines persist atomically with every dispatch counter equal to zero

#### Scenario: Save serialized draft without dispatch fields
- **WHEN** an authorized user saves a valid serialized transfer line before any dispatch occurs
- **THEN** selected serial intent persists and all dispatch counters initialize to zero without requiring application-supplied counter values

#### Scenario: Preserve existing dispatch values during schema repair
- **WHEN** the dispatch-counter default repair is applied to a database containing transfer lines
- **THEN** existing counter values remain unchanged while future omitted values receive zero

#### Scenario: Inspect production-compatible schema defaults
- **WHEN** the repaired schema is inspected on MySQL-compatible storage
- **THEN** all five dispatch counters are unsigned, non-null, and report a default of zero

### Requirement: Version 3 lifecycle is prospective and preserves legacy contracts
New transfers created after activation SHALL use version 3, whose explicit entry, allocation, visibility, inventory, and confirmation-receipt contracts supersede legacy single-route and blind-count rules for those records only. Existing versions SHALL retain their workflow, numbering, history, and return obligations. Versions SHALL not change through edit, rejection, rollout, or rollback. Version-specific actions MUST reject records of incompatible versions.

#### Scenario: Deploy with existing transfers
- **WHEN** version 3 is enabled while legacy records exist in any state
- **THEN** their data is not rewritten and their original mutation services remain authoritative

#### Scenario: Invoke old dispatch or receipt for version 3
- **WHEN** a client targets a version 3 document through legacy dispatch, blind receipt, or return endpoints
- **THEN** the request is rejected without effects

### Requirement: Version 3 approval immediately dispatches
A valid pending version 3 document SHALL transition atomically to DISPATCHED on confirmed approval with no intermediate user-operated dispatch. Rejection SHALL require a reason and preserve the submitted revision; revision and resubmission SHALL require explicit user action. Self-approval SHALL be allowed when the actor holds the relevant permission.

#### Scenario: Creator approves own request
- **WHEN** the creator also has approval permission and confirms complete valid allocations
- **THEN** approval dispatches the goods and separately records creation, submission, and approval actors even when identical

#### Scenario: Approval execution fails
- **WHEN** an allocation, inventory, serial, policy, or event write fails
- **THEN** the whole approval rolls back and the request remains pending

### Requirement: Version 3 dispatched documents have two exclusive terminal outcomes
A dispatched version 3 document SHALL permit whole-document receipt confirmation or whole-document dispatch cancellation before receipt. Cancellation SHALL require dedicated permission, explicit physical-return confirmation, and a nonempty reason, ending in CANCELLED. Completed or cancelled documents SHALL reject further inventory actions and ordinary edits; replacements SHALL require a new document.

#### Scenario: Cancel goods not received
- **WHEN** an authorized user confirms goods were not handed over or have been returned to original sources and supplies a reason
- **THEN** exact dispatch reversal succeeds atomically and the document becomes CANCELLED

#### Scenario: Cancel completed document
- **WHEN** a client attempts to cancel after receipt
- **THEN** cancellation is rejected without changing inventory or history

### Requirement: Lifecycle events are committed with their actions
The system SHALL record creation, submission, material revision, allocation progress saves, rejection, approval and dispatch, receipt/completion, and cancellation with actor, active-business context, timestamp, revision, reason where applicable, and links to immutable action evidence. Failed or replayed operations SHALL not create successful duplicate events. History viewing SHALL require the dedicated history permission.

#### Scenario: Approval and receipt by same actor
- **WHEN** one authorized user performs the end-to-end process
- **THEN** approval/dispatch and receipt/completion remain distinct recorded events

#### Scenario: Cancellation event is persisted
- **WHEN** dispatch cancellation succeeds
- **THEN** the reason and compensating inventory references are retained alongside original dispatch evidence

### Requirement: Transfer navigation reflects current work
Successful version 3 creation, draft save, submission, and allocation-progress save SHALL redirect to document detail. The list SHALL offer eligible draft submission and pending approval-workspace links under appropriate permissions. Archive SHALL be hidden on all Stock Transfer browser surfaces without removing historical archive records or legacy backend semantics.

#### Scenario: Submit from list
- **WHEN** an authorized editor submits a valid draft from the list
- **THEN** normal submission validations run and success redirects to that document's detail

#### Scenario: Open approval from list
- **WHEN** an authorized approver selects a pending transfer
- **THEN** the allocation workspace opens before any final approval can occur

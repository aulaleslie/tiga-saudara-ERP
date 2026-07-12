## ADDED Requirements

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


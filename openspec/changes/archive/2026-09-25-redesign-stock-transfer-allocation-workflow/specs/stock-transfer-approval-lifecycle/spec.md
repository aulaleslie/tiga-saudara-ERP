# Spec Delta

## ADDED Requirements

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
